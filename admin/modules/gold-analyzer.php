<?php
if (!defined('IS_ADMIN')) {
    die('Access Denied');
}

// Инициализация модуля
$MODULE_ID = 'gold-analyzer';
$TARGET_FILE = SITE_ROOT . 'index.php';
$DATA_FILE = DATA_DIR . $MODULE_ID . '.json';
$LOGS_FILE = DATA_DIR . $MODULE_ID . '_logs.json';
$FEEDBACK_FILE = DATA_DIR . $MODULE_ID . '_feedback_store.json';
$QUEUE_FILE = DATA_DIR . $MODULE_ID . '_fb_queue.json';

// Маркеры для вывода фронтенд-виджета
$START = '<?php /* BLOCK:gold_analyzer_widget HTML START */ ?>';
$END = '<?php /* BLOCK:gold_analyzer_widget HTML END */ ?>';

// Функции для логирования
function get_ga_logs($file)
{
    return file_exists($file) ? json_decode(file_get_contents($file), true) ?: [] : [];
}
function ga_log_level_weight($level)
{
    static $map = ['error' => 0, 'warning' => 1, 'info' => 2, 'debug' => 3, 'trace' => 4];
    $level = strtolower(trim((string) $level));
    return $map[$level] ?? 2;
}

function ga_status_to_level($status)
{
    $status = strtolower(trim((string) $status));
    if ($status === 'trace') {
        return 'trace';
    }
    if ($status === 'debug') {
        return 'debug';
    }
    if ($status === 'error') {
        return 'error';
    }
    if ($status === 'warning' || $status === 'warn') {
        return 'warning';
    }
    if ($status === 'success') {
        return 'info';
    }
    return 'info';
}

function ga_truncate_text($value, $limit)
{
    $value = (string) $value;
    $limit = max(100, (int) $limit);
    if (ga_mb_strlen($value) <= $limit) {
        return $value;
    }
    return ga_mb_substr($value, 0, $limit) . '... [truncated]';
}

function ga_mask_secrets($value)
{
    $value = (string) $value;
    $patterns = [
        '/AIza[0-9A-Za-z\-_]{20,}/' => 'AIza***REDACTED***',
        '/goldapi-[0-9A-Za-z\-]+/' => 'goldapi-***REDACTED***',
        '/\b\d{8,}:[A-Za-z0-9_\-]{20,}\b/' => '***TG_BOT_TOKEN_REDACTED***',
        '/(bot\d{8,}:[A-Za-z0-9_\-]{20,})/i' => 'bot***REDACTED***',
        '/("?(?:key|token|api_key|apikey|secret|password)"?\s*:\s*")[^"]+(")/i' => '$1***REDACTED***$2',
    ];
    foreach ($patterns as $pattern => $replace) {
        $value = preg_replace($pattern, $replace, $value);
    }
    return $value;
}

function ga_parse_csv_list($value, array $fallback)
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $parts = array_map('trim', explode(',', $value));
    $parts = array_values(array_filter($parts, function ($v) {
        return $v !== '';
    }));
    return empty($parts) ? $fallback : array_values(array_unique($parts));
}

function ga_mb_strlen($value)
{
    $value = (string) $value;
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function ga_mb_substr($value, $start, $length = null)
{
    $value = (string) $value;
    $start = (int) $start;
    if ($length !== null) {
        $length = (int) $length;
    }
    if (function_exists('mb_substr')) {
        return $length === null
            ? (string) mb_substr($value, $start, null, 'UTF-8')
            : (string) mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? (string) substr($value, $start) : (string) substr($value, $start, $length);
}

function ga_mb_strtolower($value)
{
    $value = (string) $value;
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function ga_random_hex($bytes = 8)
{
    $bytes = max(2, min(64, (int) $bytes));
    try {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
    } catch (Exception $e) {
        // fallback below
    }
    $out = '';
    for ($i = 0; $i < $bytes * 2; $i++) {
        $out .= dechex(mt_rand(0, 15));
    }
    return $out;
}

function ga_normalize_feedback_tokens($tokens)
{
    if (is_string($tokens)) {
        $tokens = ga_parse_csv_list($tokens, []);
    }
    if (!is_array($tokens)) {
        return [];
    }
    $out = [];
    foreach ($tokens as $token) {
        $token = preg_replace('/[^a-z0-9]/i', '', (string) $token);
        if ($token !== '') {
            $out[] = substr(strtolower($token), 0, 64);
        }
    }
    return array_values(array_unique($out));
}

function ga_is_feedback_token_valid($provided_token, $saved_token, $legacy_tokens = [])
{
    $provided_token = trim((string) $provided_token);
    $saved_token = trim((string) $saved_token);
    if ($provided_token === '' || $saved_token === '') {
        return false;
    }
    if ((function_exists('hash_equals') && hash_equals($saved_token, $provided_token)) || $saved_token === $provided_token) {
        return true;
    }
    $legacy_tokens = ga_normalize_feedback_tokens($legacy_tokens);
    foreach ($legacy_tokens as $legacy) {
        if ((function_exists('hash_equals') && hash_equals($legacy, $provided_token)) || $legacy === $provided_token) {
            return true;
        }
    }
    return false;
}

function ga_build_module_url($module_id, array $params = [], array $opts = [])
{
    $base_url = trim((string) ($opts['base_url'] ?? ''));
    $force_https = !empty($opts['force_https']);

    $https_on = (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && (string) $_SERVER['HTTPS'] !== '');
    $forwarded_proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $request_scheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
    $scheme = ($https_on || $forwarded_proto === 'https' || $request_scheme === 'https') ? 'https' : 'http';
    $host = 'localhost';
    $path = '/admin.php';

    if ($base_url !== '') {
        if (!preg_match('#^https?://#i', $base_url)) {
            $base_url = 'https://' . ltrim($base_url, '/');
        }
        $parsed = @parse_url($base_url);
        if (is_array($parsed) && !empty($parsed['host'])) {
            $scheme = strtolower((string) ($parsed['scheme'] ?? $scheme));
            $host = (string) $parsed['host'];
            if (!empty($parsed['port'])) {
                $host .= ':' . (int) $parsed['port'];
            }
            $path = (string) ($parsed['path'] ?? '/admin.php');
            if ($path === '' || $path === '/') {
                $path = '/admin.php';
            }
        }
    } else {
        $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($host === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host)[0]);
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin.php');
        $path = explode('?', $uri)[0];
        if ($path === '') {
            $path = '/admin.php';
        }
    }

    if ($force_https) {
        $scheme = 'https';
    }

    $query = array_merge(['module' => $module_id], $params);
    return $scheme . '://' . $host . $path . '?' . http_build_query($query);
}

// Асинхронный "пинок" воркера (отправляет GET и сразу закрывает соединение)
function ga_trigger_worker_async($module_id, $cron_token)
{
    $url = ga_build_module_url($module_id, ['action' => 'run_worker', 'cron_token' => $cron_token]);
    $parts = parse_url($url);
    $host = $parts['host'];
    $port = isset($parts['port']) ? $parts['port'] : ($parts['scheme'] === 'https' ? 443 : 80);
    $path = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $scheme = ($parts['scheme'] === 'https' ? 'ssl://' : '');

    // Пытаемся инициировать соединение и отправить запрос максимально быстро
    $fp = @fsockopen($scheme . $host, $port, $errno, $errstr, 2);
    if ($fp) {
        stream_set_timeout($fp, 1);
        $out = "GET $path HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "User-Agent: GA-Worker-Trigger/1.0\r\n";
        $out .= "Connection: close\r\n\r\n";
        fwrite($fp, $out);
        // Короткая пауза, чтобы сервер гарантированно принял запрос
        usleep(100000); 
        fclose($fp);
    }
}

function ga_build_feedback_webhook_url($module_id, array $cfg)
{
    $token = trim((string) ($cfg['feedback_token'] ?? ''));
    $base_url = trim((string) ($cfg['feedback_webhook_base_url'] ?? ''));
    $force_https = ($cfg['feedback_force_https'] ?? '1') === '1';
    return ga_build_module_url($module_id, [
        'action' => 'tg_feedback',
        'feedback_token' => $token
    ], [
        'base_url' => $base_url,
        'force_https' => $force_https
    ]);
}

function ga_get_telegram_webhook_info($token, $opts = [])
{
    $url = "https://api.telegram.org/bot{$token}/getWebhookInfo";
    $connect_timeout = max(3, min(60, (int) ($opts['connect_timeout'] ?? 10)));
    $timeout = max(5, min(120, (int) ($opts['timeout'] ?? 20)));
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($res) ? json_decode($res, true) : null;
    $ok = is_array($decoded) && !empty($decoded['ok']);
    return [
        'ok' => $ok,
        'http' => $http,
        'err' => $err,
        'raw' => is_string($res) ? $res : '',
        'result' => is_array($decoded) ? ($decoded['result'] ?? []) : []
    ];
}

function ga_set_telegram_webhook($token, $webhook_url, $opts = [])
{
    $url = "https://api.telegram.org/bot{$token}/setWebhook";
    $connect_timeout = max(3, min(60, (int) ($opts['connect_timeout'] ?? 10)));
    $timeout = max(5, min(120, (int) ($opts['timeout'] ?? 20)));
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;
    $post_fields = [
        'url' => (string) $webhook_url,
        'allowed_updates' => json_encode(['callback_query'], JSON_UNESCAPED_UNICODE)
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($res) ? json_decode($res, true) : null;
    $ok = is_array($decoded) && !empty($decoded['ok']);
    return [
        'ok' => $ok,
        'http' => $http,
        'err' => $err,
        'raw' => is_string($res) ? $res : '',
        'result' => is_array($decoded) ? ($decoded['result'] ?? false) : false
    ];
}

function ga_ensure_feedback_webhook($token, $webhook_url, $opts = [])
{
    $info = ga_get_telegram_webhook_info($token, $opts);
    $current_url = '';
    if (!empty($info['result']) && is_array($info['result'])) {
        $current_url = (string) ($info['result']['url'] ?? '');
    }

    if ($info['ok'] && $current_url === (string) $webhook_url) {
        return [
            'ok' => true,
            'changed' => false,
            'current_url' => $current_url,
            'info' => $info,
            'set' => null
        ];
    }

    $set = ga_set_telegram_webhook($token, $webhook_url, $opts);
    return [
        'ok' => (bool) $set['ok'],
        'changed' => true,
        'current_url' => $current_url,
        'info' => $info,
        'set' => $set
    ];
}

function add_ga_log($file, $type, $status, $msg, $cfg = null, $meta = null)
{
    $cfg = is_array($cfg) ? $cfg : [];
    $logging_enabled = ($cfg['logging_enabled'] ?? '1') === '1';
    if (!$logging_enabled) {
        return;
    }

    $level = ga_status_to_level($status);
    $current_level = strtolower((string) ($cfg['log_level'] ?? 'debug'));
    if (ga_log_level_weight($level) > ga_log_level_weight($current_level)) {
        return;
    }

    if (($cfg['log_success_requests'] ?? '1') !== '1' && strtolower((string) $status) === 'success') {
        return;
    }

    $max_entries = max(50, min(5000, (int) ($cfg['log_max_entries'] ?? 500)));
    $msg_limit = max(200, min(20000, (int) ($cfg['log_message_limit'] ?? 1200)));
    $include_http_body = ($cfg['log_include_http_body'] ?? '1') === '1';
    $run_id = trim((string) ($cfg['run_id'] ?? ''));

    $msg = ga_mask_secrets(ga_truncate_text($msg, $msg_limit));
    $logs = get_ga_logs($file);
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'type' => $type,
        'status' => $status,
        'level' => $level,
        'msg' => $msg
    ];
    if ($run_id !== '') {
        $entry['run_id'] = $run_id;
    }

    if (is_array($meta) && !empty($meta)) {
        $safe_meta = [];
        foreach ($meta as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $safe_meta[$k] = ga_mask_secrets((string) $v);
            } else {
                $encoded = json_encode($v, JSON_UNESCAPED_UNICODE);
                $safe_meta[$k] = ga_mask_secrets($encoded !== false ? $encoded : '[unserializable]');
            }
        }
        if (!$include_http_body) {
            foreach (['raw', 'response', 'body', 'payload'] as $key) {
                if (isset($safe_meta[$key])) {
                    $safe_meta[$key] = '[disabled by log_include_http_body]';
                }
            }
        }
        foreach ($safe_meta as $k => $v) {
            $safe_meta[$k] = ga_truncate_text($v, $msg_limit);
        }
        $entry['meta'] = $safe_meta;
    }

    array_unshift($logs, $entry);
    $logs = array_slice($logs, 0, $max_entries);
    $json = json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    @file_put_contents($file, $json, LOCK_EX);
}

function ga_send_telegram($token, $chat_id, $message, $opts = [])
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $connect_timeout = 3;
    $timeout = 5;
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;
    $disable_preview = isset($opts['disable_web_page_preview']) ? ((bool) $opts['disable_web_page_preview']) : true;
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => $disable_preview ? 'true' : 'false'
    ];
    if (!empty($opts['reply_markup']) && is_array($opts['reply_markup'])) {
        $markup_json = json_encode($opts['reply_markup'], JSON_UNESCAPED_UNICODE);
        if (is_string($markup_json) && $markup_json !== '') {
            $post_fields['reply_markup'] = $markup_json;
        }
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = false;
    if (is_string($res)) {
        $decoded = json_decode($res, true);
        $ok = is_array($decoded) && !empty($decoded['ok']);
    }

    return ['raw' => $res ?: '', 'err' => $err, 'http' => $http, 'ok' => $ok];
}

function ga_answer_telegram_callback($token, $callback_query_id, $message, $opts = [])
{
    $url = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
    $connect_timeout = max(3, min(60, (int) ($opts['connect_timeout'] ?? 10)));
    $timeout = max(5, min(120, (int) ($opts['timeout'] ?? 20)));
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;
    $show_alert = !empty($opts['show_alert']) ? 'true' : 'false';
    $cache_time = max(0, min(3600, (int) ($opts['cache_time'] ?? 0)));
    $post_fields = [
        'callback_query_id' => (string) $callback_query_id,
        'text' => ga_truncate_text((string) $message, 180),
        'show_alert' => $show_alert,
        'cache_time' => (string) $cache_time
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = false;
    if (is_string($res)) {
        $decoded = json_decode($res, true);
        $ok = is_array($decoded) && !empty($decoded['ok']);
    }
    return ['raw' => $res ?: '', 'err' => $err, 'http' => $http, 'ok' => $ok];
}

function ga_edit_telegram_reply_markup($token, $chat_id, $message_id, $reply_markup, $opts = [])
{
    if (empty($token) || $chat_id === '' || $message_id === '') {
        return ['raw' => '', 'err' => 'missing_params', 'http' => 0, 'ok' => false];
    }
    if (!is_array($reply_markup) || empty($reply_markup)) {
        return ['raw' => '', 'err' => 'empty_reply_markup', 'http' => 0, 'ok' => false];
    }
    $url = "https://api.telegram.org/bot{$token}/editMessageReplyMarkup";
    $connect_timeout = 3;
    $timeout = 5;
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;
    $post_fields = [
        'chat_id' => (string) $chat_id,
        'message_id' => (string) $message_id,
        'reply_markup' => json_encode($reply_markup, JSON_UNESCAPED_UNICODE)
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = false;
    if (is_string($res)) {
        $decoded = json_decode($res, true);
        $ok = is_array($decoded) && !empty($decoded['ok']);
        if (!$ok) {
            $desc = strtolower((string) ($decoded['description'] ?? ''));
            if (strpos($desc, 'message is not modified') !== false || strpos($desc, 'message to edit not found') !== false || strpos($desc, 'chat not found') !== false) {
                $ok = true;
            }
        }
    }
    return ['raw' => $res ?: '', 'err' => $err, 'http' => $http, 'ok' => $ok];
}

function ga_feedback_callback_data($scope, $vote, $trace = '')
{
    $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) $scope)));
    $vote = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) $vote)));
    $trace = preg_replace('/[^a-z0-9]/i', '', strtolower(trim((string) $trace)));
    if ($scope === '') {
        $scope = 'prod';
    }
    if ($vote === '') {
        $vote = 'unknown';
    }
    if ($trace === '') {
        $trace = substr(md5($scope . '|' . $vote . '|' . microtime(true)), 0, 8);
    }
    $trace = substr($trace, 0, 16);
    return 'ga_fb:' . $scope . ':' . $vote . ':' . $trace;
}

function ga_feedback_parse_callback_data($callback_data)
{
    $callback_data = trim((string) $callback_data);
    if (strpos($callback_data, 'ga_fb:') !== 0) {
        return ['ok' => false, 'scope' => 'unknown', 'action' => 'unknown', 'trace' => '', 'raw' => $callback_data];
    }
    $parts = explode(':', $callback_data, 4);
    $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string) ($parts[1] ?? 'unknown')));
    $action = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string) ($parts[2] ?? 'unknown')));
    $trace = preg_replace('/[^a-z0-9]/i', '', strtolower((string) ($parts[3] ?? '')));
    if ($scope === '') {
        $scope = 'unknown';
    }
    if ($action === '') {
        $action = 'unknown';
    }
    $trace = substr($trace, 0, 16);
    return ['ok' => true, 'scope' => $scope, 'action' => $action, 'trace' => $trace, 'raw' => $callback_data];
}

function ga_feedback_default_stats()
{
    return [
        'up' => 0,
        'down' => 0,
        'details' => 0,
        'reasons' => 0,
        'risks' => 0,
        'stats' => 0,
        'help' => 0,
        'clicks' => 0
    ];
}

function ga_feedback_normalize_stats($stats)
{
    $base = ga_feedback_default_stats();
    if (!is_array($stats)) {
        return $base;
    }
    foreach ($base as $k => $v) {
        $base[$k] = max(0, (int) ($stats[$k] ?? 0));
    }
    return $base;
}

function ga_feedback_load_store($file)
{
    $store = ['traces' => [], 'updated_at' => ''];
    if (!file_exists($file)) return $store;
    $fp = fopen($file, 'r');
    if ($fp && flock($fp, LOCK_SH)) {
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) $store = $decoded;
    }
    if (!isset($store['traces']) || !is_array($store['traces'])) $store['traces'] = [];
    return $store;
}

function ga_feedback_save_store($file, $store)
{
    if (!is_array($store)) return false;
    $store['updated_at'] = date('Y-m-d H:i:s');
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $fp = fopen($file, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    return false;
}

function ga_feedback_trim_store(&$store, $cfg)
{
    $max_traces = max(50, min(5000, (int) ($cfg['feedback_store_max_traces'] ?? 1500)));
    if (!isset($store['traces']) || !is_array($store['traces'])) {
        $store['traces'] = [];
        return;
    }
    if (count($store['traces']) <= $max_traces) {
        return;
    }
    uasort($store['traces'], function ($a, $b) {
        $ta = strtotime((string) ($a['updated_at'] ?? '1970-01-01 00:00:00'));
        $tb = strtotime((string) ($b['updated_at'] ?? '1970-01-01 00:00:00'));
        if ($ta === $tb) {
            return 0;
        }
        return ($ta > $tb) ? -1 : 1;
    });
    $store['traces'] = array_slice($store['traces'], 0, $max_traces, true);
}

function ga_feedback_boot_trace(&$store, $scope, $trace)
{
    if (!isset($store['traces']) || !is_array($store['traces'])) {
        $store['traces'] = [];
    }
    if ($trace === '') {
        $rnd = function_exists('random_int') ? random_int(1000, 9999) : mt_rand(1000, 9999);
        $trace = substr(md5($scope . '|' . microtime(true) . '|' . $rnd), 0, 10);
    }
    if (!isset($store['traces'][$trace]) || !is_array($store['traces'][$trace])) {
        $store['traces'][$trace] = [
            'scope' => $scope,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'stats' => ga_feedback_default_stats(),
            'users' => [],
            'context' => []
        ];
    }
    $store['traces'][$trace]['scope'] = $scope;
    $store['traces'][$trace]['stats'] = ga_feedback_normalize_stats($store['traces'][$trace]['stats'] ?? []);
    if (!isset($store['traces'][$trace]['users']) || !is_array($store['traces'][$trace]['users'])) {
        $store['traces'][$trace]['users'] = [];
    }
    if (!isset($store['traces'][$trace]['context']) || !is_array($store['traces'][$trace]['context'])) {
        $store['traces'][$trace]['context'] = [];
    }
    return $trace;
}

function ga_feedback_register_context($file, $cfg, $scope, $trace, $context = [])
{
    $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) $scope)));
    $trace = preg_replace('/[^a-z0-9]/i', '', strtolower(trim((string) $trace)));
    if ($scope === '') {
        $scope = 'prod';
    }
    $trace = substr($trace, 0, 16);
    if ($trace === '') {
        return false;
    }

    $store = ga_feedback_load_store($file);
    $trace = ga_feedback_boot_trace($store, $scope, $trace);

    $safe_context = [
        'scope' => $scope,
        'run_id' => trim((string) ($context['run_id'] ?? '')),
        'signal_type' => trim((string) ($context['signal_type'] ?? 'none')),
        'confidence' => (int) ($context['confidence'] ?? 0),
        'importance' => (int) ($context['importance'] ?? 0),
        'summary' => trim((string) ($context['summary'] ?? '')),
        'price' => trim((string) ($context['price'] ?? '')),
        'reasons' => is_array($context['reasoning'] ?? null) ? array_values(array_slice($context['reasoning'], 0, 8)) : [],
        'risks' => is_array($context['risks'] ?? null) ? array_values(array_slice($context['risks'], 0, 8)) : [],
        'message_preview' => ga_truncate_text(strip_tags((string) ($context['telegram_message'] ?? '')), 400),
        'created_at' => date('Y-m-d H:i:s')
    ];

    $store['traces'][$trace]['context'] = $safe_context;
    $store['traces'][$trace]['updated_at'] = date('Y-m-d H:i:s');

    ga_feedback_trim_store($store, $cfg);
    return ga_feedback_save_store($file, $store);
}

function ga_feedback_store_apply_action(&$store, $cfg, $scope, $action, $trace, $user_id, $username = '', $chat_id = '', $message_id = '')
{
    $scope = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) $scope)));
    $action = preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string) $action)));
    $trace = preg_replace('/[^a-z0-9]/i', '', strtolower(trim((string) $trace)));
    $user_id = trim((string) $user_id);
    $username = trim((string) $username);
    $chat_id = trim((string) $chat_id);
    $message_id = trim((string) $message_id);

    if ($scope === '') {
        $scope = 'prod';
    }
    if ($action === '') {
        $action = 'unknown';
    }
    $trace = substr($trace, 0, 16);

    $trace = ga_feedback_boot_trace($store, $scope, $trace);
    $node = &$store['traces'][$trace];

    if ($chat_id !== '') {
        $node['chat_id'] = $chat_id;
    }
    if ($message_id !== '') {
        $node['message_id'] = $message_id;
    }

    $supported_actions = ['up', 'down', 'details', 'reasons', 'risks', 'stats', 'help'];
    if (!in_array($action, $supported_actions, true)) {
        $action = 'help';
    }

    $stats = ga_feedback_normalize_stats($node['stats'] ?? []);
    $duplicate_vote = false;

    if ($action === 'up' || $action === 'down') {
        if ($user_id !== '') {
            $prev_vote = strtolower((string) ($node['users'][$user_id]['vote'] ?? ''));
            if ($prev_vote === $action) {
                $duplicate_vote = true;
            } else {
                if (in_array($prev_vote, ['up', 'down'], true) && $stats[$prev_vote] > 0) {
                    $stats[$prev_vote]--;
                }
                $stats[$action]++;
                $node['users'][$user_id] = [
                    'vote' => $action,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'username' => $username
                ];
            }
        } else {
            $stats[$action]++;
        }
    } else {
        $stats[$action]++;
    }
    $stats['clicks']++;

    $node['stats'] = $stats;
    $node['updated_at'] = date('Y-m-d H:i:s');

    $max_users = max(100, min(20000, (int) ($cfg['feedback_store_max_users'] ?? 5000)));
    if (count($node['users']) > $max_users) {
        uasort($node['users'], function ($a, $b) {
            $ta = strtotime((string) ($a['updated_at'] ?? '1970-01-01 00:00:00'));
            $tb = strtotime((string) ($b['updated_at'] ?? '1970-01-01 00:00:00'));
            if ($ta === $tb) {
                return 0;
            }
            return ($ta > $tb) ? -1 : 1;
        });
        $node['users'] = array_slice($node['users'], 0, $max_users, true);
    }

    ga_feedback_trim_store($store, $cfg);

    return [
        'action' => $action,
        'scope' => $scope,
        'trace' => $trace,
        'stats' => $stats,
        'context' => is_array($node['context'] ?? null) ? $node['context'] : [],
        'voters' => count($node['users']),
        'duplicate_vote' => $duplicate_vote
    ];
}

function ga_feedback_build_action_response($cfg, $action_result)
{
    $action = (string) ($action_result['action'] ?? 'help');
    $stats = ga_feedback_normalize_stats($action_result['stats'] ?? []);
    $context = is_array($action_result['context'] ?? null) ? $action_result['context'] : [];
    $voters = (int) ($action_result['voters'] ?? 0);
    $duplicate_vote = !empty($action_result['duplicate_vote']);

    $show_alert = false;
    $text = trim((string) ($cfg['feedback_thanks_text'] ?? 'Спасибо за обратную связь!'));
    $full_text = '';

    if ($action === 'up') {
        $text = $duplicate_vote ? 'Ваш голос 👍 уже учтен.' : 'Голос 👍 принят.';
        $text .= " За: {$stats['up']} | Против: {$stats['down']}";
    } elseif ($action === 'down') {
        $text = $duplicate_vote ? 'Ваш голос 👎 уже учтен.' : 'Голос 👎 принят.';
        $text .= " За: {$stats['up']} | Против: {$stats['down']}";
    } elseif ($action === 'details') {
        $show_alert = true;
        $signal = strtoupper((string) ($context['signal_type'] ?? 'NONE'));
        $confidence = max(0, min(100, (int) ($context['confidence'] ?? 0)));
        $importance = max(0, min(100, (int) ($context['importance'] ?? 0)));
        $summary = trim((string) ($context['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Подробности недоступны для этого сообщения.';
        }
        $text = "Сигнал: {$signal}\nУверенность: {$confidence}%\nВажность: {$importance}%\n" . ga_truncate_text($summary, 120);
        $full_text = "<b>📊 ДЕТАЛЬНЫЙ ОТЧЕТ ПО СИГНАЛУ</b>\n\n<b>Направление:</b> " . ($signal === 'BUY' ? '🟢 ПОКУПКА' : ($signal === 'SELL' ? '🔴 ПРОДАЖА' : '🟡 ОЖИДАНИЕ')) . "\n<b>Вероятность успеха:</b> {$confidence}%\n<b>Приоритет:</b> {$importance}%\n\n<b>Аналитическая сводка:</b>\n<i>" . h($summary) . "</i>";
    } elseif ($action === 'reasons') {
        $show_alert = true;
        $reasons = is_array($context['reasons'] ?? null) ? $context['reasons'] : [];
        $reasons = array_values(array_filter(array_map('trim', $reasons), function ($v) {
            return $v !== '';
        }));
        if (empty($reasons)) {
            $text = 'Причины сигнала не были сохранены.';
        } else {
            $text = "Почему сигнал:\n• " . implode("\n• ", array_slice($reasons, 0, 4));
            $full_text = "<b>🧠 ТЕХНИЧЕСКОЕ ОБОСНОВАНИЕ</b>\n\n" . implode("\n\n", array_map(function($r){ return "🔸 " . h($r); }, $reasons));
        }
        $text = ga_truncate_text($text, 180);
    } elseif ($action === 'risks') {
        $show_alert = true;
        $risks = is_array($context['risks'] ?? null) ? $context['risks'] : [];
        $risks = array_values(array_filter(array_map('trim', $risks), function ($v) {
            return $v !== '';
        }));
        if (empty($risks)) {
            $text = 'Риски не указаны.';
        } else {
            $text = "Риски:\n• " . implode("\n• ", array_slice($risks, 0, 4));
            $full_text = "<b>⚠️ ВНИМАНИЕ: РИСКИ</b>\n\n" . implode("\n\n", array_map(function($r){ return "📍 " . h($r); }, $risks)) . "\n\n<i>Всегда соблюдайте риск-менеджмент!</i>";
        }
        $text = ga_truncate_text($text, 180);
    } elseif ($action === 'stats') {
        $show_alert = true;
        $text = "Статистика:\n👍 {$stats['up']} | 👎 {$stats['down']}\n";
        $text .= "Детали: {$stats['details']} | Почему: {$stats['reasons']} | Риски: {$stats['risks']}\n";
        $text .= "Нажатий: {$stats['clicks']} | Уник. голосов: {$voters}";
        $text = ga_truncate_text($text, 180);
        $full_text = "<b>📊 Статистика активности:</b>\n\n👍 Полезно: <b>{$stats['up']}</b>\n👎 Мимо: <b>{$stats['down']}</b>\n\n🔍 Просмотров деталей: {$stats['details']}\n🧠 Изучали причины: {$stats['reasons']}\n⚠️ Оценивали риски: {$stats['risks']}\n\n👥 Всего уникальных голосов: {$voters}\n🖱 Всего взаимодействий: {$stats['clicks']}";
    } elseif ($action === 'help') {
        $show_alert = true;
        $text = "Кнопки:\n👍/👎 голос\n🤔 детали\n🧠 причины\n⚠️ риски\n📊 статистика";
        $full_text = "<b>❓ Как пользоваться кнопками:</b>\n\n👍/👎 — оцените точность сигнала\n🤔 <b>Детали</b> — краткая сводка\n🧠 <b>Почему</b> — аргументы ИИ\n⚠️ <b>Риски</b> — на что обратить внимание\n📊 <b>Статистика</b> — общая реакция сообщества";
    }

    return [
        'text' => ga_truncate_text($text, 180),
        'show_alert' => $show_alert,
        'full_text' => $full_text
    ];
}

function ga_feedback_with_counter_text($label, $count, $cfg)
{
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }
    $show = ($cfg['feedback_show_counters'] ?? '1') === '1';
    if (!$show) {
        return $label;
    }
    $count = max(0, (int) $count);
    return $label . ' ' . $count;
}

function ga_build_feedback_markup($cfg, $context = [])
{
    if (!is_array($cfg)) {
        return null;
    }
    if (($cfg['feedback_buttons_enabled'] ?? '1') !== '1') {
        return null;
    }

    $scope = strtolower(trim((string) ($context['scope'] ?? 'prod')));
    if ($scope === '') {
        $scope = 'prod';
    }
    $is_test_scope = in_array($scope, ['test', 'manual_test'], true);
    if ($is_test_scope && ($cfg['feedback_include_test'] ?? '1') !== '1') {
        return null;
    }

    $trace = preg_replace('/[^a-z0-9]/i', '', strtolower(trim((string) ($context['trace'] ?? ''))));
    if ($trace === '') {
        $base = (string) ($context['run_id'] ?? '') . '|' . (string) ($context['signal'] ?? '') . '|' . $scope;
        $trace = substr(md5($base), 0, 10);
    }
    $trace = substr($trace, 0, 16);

    $positive_text = trim((string) ($cfg['feedback_positive_text'] ?? '👍 Полезно'));
    $negative_text = trim((string) ($cfg['feedback_negative_text'] ?? '👎 Мимо'));
    $neutral_text = trim((string) ($cfg['feedback_neutral_text'] ?? '🤔 Нужны детали'));
    $reasons_text = trim((string) ($cfg['feedback_reasons_text'] ?? '🧠 Почему'));
    $risks_text = trim((string) ($cfg['feedback_risks_text'] ?? '⚠️ Риски'));
    $stats_text = trim((string) ($cfg['feedback_stats_text'] ?? '📊 Статистика'));
    $help_text = trim((string) ($cfg['feedback_help_text'] ?? '❓ Помощь'));
    $support_text = trim((string) ($cfg['feedback_support_text'] ?? '💬 Связаться'));
    $support_url = trim((string) ($cfg['feedback_support_url'] ?? ''));
    $stats = ga_feedback_normalize_stats($context['stats'] ?? []);

    $row1 = [];
    if ($positive_text !== '') {
        $row1[] = [
            'text' => ga_feedback_with_counter_text($positive_text, $stats['up'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'up', $trace)
        ];
    }
    if ($negative_text !== '') {
        $row1[] = [
            'text' => ga_feedback_with_counter_text($negative_text, $stats['down'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'down', $trace)
        ];
    }
    if ($neutral_text !== '') {
        $row1[] = [
            'text' => ga_feedback_with_counter_text($neutral_text, $stats['details'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'details', $trace)
        ];
    }

    $row2 = [];
    if ($reasons_text !== '') {
        $row2[] = [
            'text' => ga_feedback_with_counter_text($reasons_text, $stats['reasons'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'reasons', $trace)
        ];
    }
    if ($risks_text !== '') {
        $row2[] = [
            'text' => ga_feedback_with_counter_text($risks_text, $stats['risks'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'risks', $trace)
        ];
    }

    $row3 = [];
    if ($stats_text !== '') {
        $row3[] = [
            'text' => ga_feedback_with_counter_text($stats_text, $stats['stats'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'stats', $trace)
        ];
    }
    if ($help_text !== '') {
        $row3[] = [
            'text' => ga_feedback_with_counter_text($help_text, $stats['help'], $cfg),
            'callback_data' => ga_feedback_callback_data($scope, 'help', $trace)
        ];
    }

    $keyboard = [];
    if (!empty($row1)) {
        $keyboard[] = $row1;
    }
    if (!empty($row2)) {
        $keyboard[] = $row2;
    }
    if (!empty($row3)) {
        $keyboard[] = $row3;
    }
    if ($support_text !== '' && $support_url !== '' && preg_match('/^https?:\/\//i', $support_url)) {
        $keyboard[] = [[
            'text' => $support_text,
            'url' => $support_url
        ]];
    }

    if (empty($keyboard)) {
        return null;
    }

    return ['inline_keyboard' => $keyboard];
}

function ga_supported_gemini_models()
{
    return [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-flash-latest',
        'gemini-3-flash-preview',
        'gemini-2.0-flash',
        'gemini-2.0-flash-001'
    ];
}

function ga_normalize_gemini_model($model)
{
    $model = trim((string) $model);
    if (strpos($model, 'models/') === 0) {
        $model = substr($model, 7);
    }
    if ($model === '') {
        return 'gemini-2.5-flash';
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
        return 'gemini-2.5-flash';
    }
    return $model;
}

function ga_is_model_not_found($status, $raw)
{
    if ((int) $status === 404) {
        return true;
    }

    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $raw_l = ga_mb_strtolower($raw);
    return (strpos($raw_l, 'model') !== false && strpos($raw_l, 'not found') !== false)
        || (strpos($raw_l, 'models/') !== false && strpos($raw_l, 'not found') !== false);
}

function ga_detect_gemini_error_type($status, $raw)
{
    $status = (int) $status;
    $raw = is_string($raw) ? $raw : '';
    $msg = $raw;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $msg = (string) ($decoded['error']['message'] ?? $raw);
    }
    $msg_l = ga_mb_strtolower($msg);

    if ($status === 400 && (strpos($msg_l, 'user location is not supported') !== false || strpos($msg_l, 'location is not supported') !== false)) {
        return 'location_not_supported';
    }
    if ($status === 429 || strpos($msg_l, 'quota') !== false || strpos($msg_l, 'rate limit') !== false) {
        return 'rate_limited';
    }
    if ($status === 401 || $status === 403 || strpos($msg_l, 'api key') !== false || strpos($msg_l, 'permission') !== false) {
        return 'auth_or_permission';
    }
    if (ga_is_model_not_found($status, $raw)) {
        return 'model_not_found';
    }
    if ($status >= 500) {
        return 'server_error';
    }
    return 'unknown';
}

function ga_gemini_model_candidates($preferred_model, $fallback_models_csv = '')
{
    $preferred_model = trim((string) $preferred_model);
    $list = [];

    if ($preferred_model !== '') {
        $list[] = $preferred_model;
    }

    $default = [
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-3-flash-preview',
        'gemini-2.5-flash-lite',
        'gemini-2.0-flash'
    ];
    $list = array_merge($list, ga_parse_csv_list($fallback_models_csv, $default));

    return array_values(array_unique($list));
}

function ga_model_short_name($name)
{
    $name = trim((string) $name);
    if (strpos($name, 'models/') === 0) {
        return substr($name, 7);
    }
    return $name;
}

function ga_list_gemini_models($api_key, $api_version, $opts = [])
{
    $api_key = trim((string) $api_key);
    $api_version = trim((string) $api_version);
    $connect_timeout = max(3, min(60, (int) ($opts['connect_timeout'] ?? 10)));
    $timeout = max(5, min(120, (int) ($opts['timeout'] ?? 15)));
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;

    $url = 'https://generativelanguage.googleapis.com/' . $api_version . '/models?pageSize=1000';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-goog-api-key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $models = [];
    if ($raw !== false && $status === 200) {
        $j = json_decode((string) $raw, true);
        $items = $j['models'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = ga_model_short_name($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $methods = [];
                if (!empty($item['supportedGenerationMethods']) && is_array($item['supportedGenerationMethods'])) {
                    $methods = $item['supportedGenerationMethods'];
                } elseif (!empty($item['supportedActions']) && is_array($item['supportedActions'])) {
                    $methods = $item['supportedActions'];
                }

                $supports_generate = false;
                foreach ($methods as $m) {
                    $m = strtolower((string) $m);
                    if ($m === 'generatecontent' || strpos($m, 'generatecontent') !== false) {
                        $supports_generate = true;
                        break;
                    }
                }
                if ($supports_generate) {
                    $models[] = $name;
                }
            }
        }
    }

    return [
        'ok' => ($raw !== false && $status === 200),
        'status' => $status,
        'raw' => is_string($raw) ? $raw : '',
        'err' => $err,
        'models' => array_values(array_unique($models))
    ];
}

function ga_call_gemini($api_key, $preferred_model, array $payload, $opts = [])
{
    $api_key = trim((string) $api_key);
    $timeout = max(5, min(120, (int) ($opts['timeout'] ?? 30)));
    $connect_timeout = max(3, min(60, (int) ($opts['connect_timeout'] ?? 10)));
    $ssl_verify = ($opts['ssl_verify'] ?? true) ? true : false;
    $discover_models = ($opts['discover_models'] ?? true) ? true : false;
    $discovery_timeout = max(5, min(90, (int) ($opts['discovery_timeout'] ?? 15)));
    $model_candidates = ga_gemini_model_candidates($preferred_model, (string) ($opts['fallback_models_csv'] ?? ''));
    $api_versions = ga_parse_csv_list((string) ($opts['api_versions_csv'] ?? ''), ['v1beta', 'v1']);

    // --- BRIDGE LOGIC ---
    if (($opts['use_bridge'] ?? '0') === '1' && !empty($opts['bridge_url'])) {
        $bridge_payload = [
            'model' => $preferred_model,
            'api_version' => $api_versions[0] ?? 'v1beta',
            'payload' => $payload
        ];
        $ch = curl_init($opts['bridge_url']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Bridge-Secret: ' . ($opts['bridge_secret'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bridge_payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => ($raw !== false && $status === 200),
            'status' => $status,
            'raw' => is_string($raw) ? $raw : '',
            'err' => $err,
            'model' => $preferred_model,
            'api_version' => 'bridge',
            'error_type' => ($status !== 200) ? 'bridge_error' : '',
            'attempts' => ['Bridge Request => HTTP ' . $status],
            'discovery' => []
        ];
    }

    $discovered = [];

    $last = [
        'ok' => false,
        'status' => 0,
        'raw' => '',
        'err' => 'No attempts made',
        'model' => '',
        'api_version' => '',
        'error_type' => 'unknown',
        'attempts' => [],
        'discovery' => []
    ];

    if ($discover_models) {
        foreach ($api_versions as $api_version) {
            $lm = ga_list_gemini_models($api_key, $api_version, [
                'connect_timeout' => $connect_timeout,
                'timeout' => $discovery_timeout,
                'ssl_verify' => $ssl_verify
            ]);
            $preview = is_string($lm['raw']) ? ga_mb_substr($lm['raw'], 0, 200) : '';
            $last['discovery'][] = $api_version . ' => HTTP ' . (int) $lm['status'] . ($lm['err'] ? ' cURL:' . $lm['err'] : '') . (!empty($lm['models']) ? ' models:' . count($lm['models']) : '') . ($preview !== '' ? ' Raw:' . $preview : '');
            $discovery_error_type = ga_detect_gemini_error_type((int) $lm['status'], (string) ($lm['raw'] ?? ''));
            if ($discovery_error_type === 'location_not_supported') {
                $last['status'] = (int) $lm['status'];
                $last['raw'] = (string) ($lm['raw'] ?? '');
                $last['err'] = (string) ($lm['err'] ?? '');
                $last['api_version'] = $api_version;
                $last['error_type'] = $discovery_error_type;
                $last['attempts'][] = $api_version . '/* => STOP(location_not_supported during ListModels)';
                return $last;
            }
            if ($lm['ok']) {
                $discovered[$api_version] = $lm['models'];
            }
        }
        $all_discovered = [];
        foreach ($discovered as $list) {
            $all_discovered = array_merge($all_discovered, $list);
        }
        if (!empty($all_discovered)) {
            $model_candidates = array_values(array_unique(array_merge($model_candidates, $all_discovered)));
        } else {
            $last['discovery'][] = 'no_generate_models_discovered';
        }
    }

    foreach ($model_candidates as $model) {
        foreach ($api_versions as $api_version) {
            if (isset($discovered[$api_version]) && !empty($discovered[$api_version]) && !in_array($model, $discovered[$api_version], true)) {
                $last['attempts'][] = $api_version . '/' . $model . ' => SKIP(not in ListModels generateContent)';
                continue;
            }
            $url = 'https://generativelanguage.googleapis.com/' . $api_version . '/models/' . $model . ':generateContent';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $api_key
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $raw = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $preview = is_string($raw) ? ga_mb_substr($raw, 0, 200) : '';
            $last['attempts'][] = $api_version . '/' . $model . ' => HTTP ' . $status . ($err ? ' cURL:' . $err : '') . ($preview !== '' ? ' Raw:' . $preview : '');

            if ($raw !== false && $status === 200) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'raw' => (string) $raw,
                    'err' => '',
                    'model' => $model,
                    'api_version' => $api_version,
                    'error_type' => '',
                    'attempts' => $last['attempts'],
                    'discovery' => $last['discovery']
                ];
            }

            $error_type = ga_detect_gemini_error_type($status, is_string($raw) ? $raw : '');
            $last = [
                'ok' => false,
                'status' => $status,
                'raw' => is_string($raw) ? $raw : '',
                'err' => $err,
                'model' => $model,
                'api_version' => $api_version,
                'error_type' => $error_type,
                'attempts' => $last['attempts'],
                'discovery' => $last['discovery']
            ];

            if ($error_type === 'location_not_supported') {
                return $last;
            }
            if (!ga_is_model_not_found($status, is_string($raw) ? $raw : '')) {
                return $last;
            }
        }
    }

    return $last;
}

$defaults = [
    'bot_active' => '1',
    'test_mode' => '0',
    'cron_token' => ga_random_hex(12),
    'goldapi_key' => '',
    'gemini_key' => '',
    'tg_token' => '',
    'tg_chat_id' => '',
    'gemini_model' => 'gemini-2.5-flash',
    'threshold' => '70',
    'use_ai' => '1',
    'gemini_api_versions' => 'v1beta,v1',
    'gemini_fallback_models' => 'gemini-2.5-flash,gemini-flash-latest,gemini-3-flash-preview,gemini-2.5-flash-lite,gemini-2.0-flash',
    'gemini_discover_models' => '1',
    'gemini_discovery_timeout' => '15',
    'curl_ssl_verify' => '1',
    'curl_connect_timeout' => '3',
    'gold_timeout' => '25',
    'ai_timeout' => '30',
    'tg_timeout' => '7',
    'logging_enabled' => '1',
    'log_level' => 'debug',
    'log_max_entries' => '500',
    'log_message_limit' => '1200',
    'log_include_http_body' => '1',
    'log_success_requests' => '1',
    'feedback_buttons_enabled' => '1',
    'feedback_include_test' => '1',
    'feedback_auto_webhook' => '1',
    'feedback_force_https' => '1',
    'feedback_webhook_base_url' => '',
    'feedback_token' => '',
    'feedback_token_legacy' => [],
    'feedback_show_counters' => '1',
    'feedback_positive_text' => '👍 Полезно',
    'feedback_negative_text' => '👎 Мимо',
    'feedback_neutral_text' => '🤔 Нужны детали',
    'feedback_reasons_text' => '🧠 Почему',
    'feedback_risks_text' => '⚠️ Риски',
    'feedback_stats_text' => '📊 Статистика',
    'feedback_help_text' => '❓ Помощь',
    'feedback_thanks_text' => 'Спасибо за обратную связь!',
    'feedback_support_text' => '💬 Связаться',
    'feedback_support_url' => '',
    'feedback_store_max_traces' => '1500',
    'feedback_store_max_users' => '5000',
    'use_bridge' => '0',
    'bridge_url' => '',
    'bridge_secret' => '',
    'prompt' => "Ты профессиональный финансовый аналитик рынка золота (XAU/USD).\nТвоя задача: проанализировать текущие котировки и выдать результат СТРОГО в формате JSON.\n\nТРЕБОВАНИЯ К ОТВЕТУ:\n1. Обязательно заполни ВСЕ поля JSON.\n2. Если значимых изменений цены нет, используй signal_type: \"hold\" и важность importance: 10.\n3. Поле telegram_message должно содержать готовый HTML-текст для публикации.\n\nФОРМАТ JSON (обязателен):\n{\n  \"summary\": \"краткое описание ситуации\",\n  \"signal_type\": \"buy|sell|hold|none\",\n  \"confidence\": 0-100,\n  \"importance\": 0-100,\n  \"reasoning\": [\"причина 1\", \"причина 2\"],\n  \"risks\": [\"риск 1\"],\n  \"telegram_message\": \"<b>HTML текст сообщения</b>\"\n}",
    'module_css' => ".xau-widget { border-radius: 8px; background: #222; border: 1px solid #d4af37; color: #fff; padding: 20px; font-family: sans-serif; display: inline-block; box-shadow: 0 4px 15px rgba(212, 175, 55, 0.1); } .xau-price { font-size: 28px; font-weight: bold; color: #d4af37; } .xau-trend.up { color: #89d185; } .xau-trend.down { color: #f48771; } .xau-summary { margin-top: 15px; font-size: 14px; opacity: 0.9; }",
    'module_js' => '',
    'last_price' => 'Нет данных',
    'last_summary' => 'Ожидание сбора...',
    'last_raw_gold' => [],
    'last_raw_ai' => []
];

// Данные загружаем с объединением из json файла. 
// Мы не парсим html контент для входных данных настроек.
$data = [];
if (file_exists($DATA_FILE)) {
    $parsed_j = json_decode(file_get_contents($DATA_FILE), true);
    if (is_array($parsed_j))
        $data = $parsed_j;
}
$data = array_merge($defaults, $data);
$data['gemini_model'] = ga_normalize_gemini_model($data['gemini_model']);
if ($data['gemini_model'] === 'gemini-1.5-flash-8b') {
    $data['gemini_model'] = 'gemini-2.5-flash';
}
$data['curl_connect_timeout'] = (string) max(3, min(60, (int) $data['curl_connect_timeout']));
$data['gold_timeout'] = (string) max(5, min(120, (int) $data['gold_timeout']));
$data['ai_timeout'] = (string) max(5, min(180, (int) $data['ai_timeout']));
$data['tg_timeout'] = (string) max(5, min(120, (int) $data['tg_timeout']));
$data['gemini_discovery_timeout'] = (string) max(5, min(90, (int) $data['gemini_discovery_timeout']));
$data['gemini_discover_models'] = ($data['gemini_discover_models'] ?? '1') === '1' ? '1' : '0';
$data['curl_ssl_verify'] = ($data['curl_ssl_verify'] ?? '1') === '1' ? '1' : '0';
$data['logging_enabled'] = ($data['logging_enabled'] ?? '1') === '1' ? '1' : '0';
$data['log_include_http_body'] = ($data['log_include_http_body'] ?? '1') === '1' ? '1' : '0';
$data['log_success_requests'] = ($data['log_success_requests'] ?? '1') === '1' ? '1' : '0';
$data['feedback_buttons_enabled'] = ($data['feedback_buttons_enabled'] ?? '1') === '1' ? '1' : '0';
$data['feedback_include_test'] = ($data['feedback_include_test'] ?? '1') === '1' ? '1' : '0';
$data['feedback_auto_webhook'] = ($data['feedback_auto_webhook'] ?? '1') === '1' ? '1' : '0';
$data['feedback_force_https'] = ($data['feedback_force_https'] ?? '1') === '1' ? '1' : '0';
$data['feedback_webhook_base_url'] = trim((string) ($data['feedback_webhook_base_url'] ?? ''));
$data['feedback_show_counters'] = ($data['feedback_show_counters'] ?? '1') === '1' ? '1' : '0';
$data['feedback_token'] = trim((string) ($data['feedback_token'] ?? ''));
$data['feedback_token_legacy'] = ga_normalize_feedback_tokens($data['feedback_token_legacy'] ?? []);
if ($data['feedback_token'] === '') {
    $cron_seed = trim((string) ($data['cron_token'] ?? ''));
    if ($cron_seed !== '') {
        $data['feedback_token'] = substr(hash('sha256', 'ga-feedback|' . $cron_seed), 0, 24);
    } else {
        $data['feedback_token'] = ga_random_hex(12);
    }
}
$data['feedback_token_legacy'] = array_values(array_filter($data['feedback_token_legacy'], function ($token) use ($data) {
    return $token !== '' && $token !== strtolower((string) $data['feedback_token']);
}));
$data['feedback_positive_text'] = trim((string) ($data['feedback_positive_text'] ?? '👍 Полезно'));
$data['feedback_negative_text'] = trim((string) ($data['feedback_negative_text'] ?? '👎 Мимо'));
$data['feedback_neutral_text'] = trim((string) ($data['feedback_neutral_text'] ?? '🤔 Нужны детали'));
$data['feedback_reasons_text'] = trim((string) ($data['feedback_reasons_text'] ?? '🧠 Почему'));
$data['feedback_risks_text'] = trim((string) ($data['feedback_risks_text'] ?? '⚠️ Риски'));
$data['feedback_stats_text'] = trim((string) ($data['feedback_stats_text'] ?? '📊 Статистика'));
$data['feedback_help_text'] = trim((string) ($data['feedback_help_text'] ?? '❓ Помощь'));
$data['feedback_thanks_text'] = trim((string) ($data['feedback_thanks_text'] ?? 'Спасибо за обратную связь!'));
$data['feedback_support_text'] = trim((string) ($data['feedback_support_text'] ?? '💬 Связаться'));
$data['feedback_support_url'] = trim((string) ($data['feedback_support_url'] ?? ''));
$data['feedback_store_max_traces'] = (string) max(50, min(5000, (int) ($data['feedback_store_max_traces'] ?? 1500)));
$data['feedback_store_max_users'] = (string) max(100, min(20000, (int) ($data['feedback_store_max_users'] ?? 5000)));
if (!in_array($data['log_level'], ['error', 'warning', 'info', 'debug', 'trace'], true)) {
    $data['log_level'] = 'debug';
}
$data['log_max_entries'] = (string) max(50, min(5000, (int) $data['log_max_entries']));
$data['log_message_limit'] = (string) max(200, min(20000, (int) $data['log_message_limit']));

// Вебхук для feedback-кнопок Telegram (callback_query)
if (isset($_GET['action']) && $_GET['action'] === 'tg_feedback') {
    $provided_token = trim((string) ($_GET['feedback_token'] ?? ''));
    $saved_token = (string) ($data['feedback_token'] ?? '');
    if (!ga_is_feedback_token_valid($provided_token, $saved_token, ga_normalize_feedback_tokens($data['feedback_token_legacy'] ?? []))) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'forbidden']));
    }

    $raw_update = file_get_contents('php://input');
    $update = json_decode((string) $raw_update, true);
    $callback = $update['callback_query'] ?? null;
    
    if (is_array($callback)) {
        $callback_id = (string) ($callback['id'] ?? '');
        $callback_data = (string) ($callback['data'] ?? '');

        // Главный тумблер: если бот выключен — не обрабатываем очередь и не запускаем воркер.
        if (($data['bot_active'] ?? '0') !== '1') {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode([
                'method' => 'answerCallbackQuery',
                'callback_query_id' => $callback_id,
                'text' => 'Бот выключен админом.'
            ]));
        }
        
        // Определяем текст быстрого ответа
        $p = ga_feedback_parse_callback_data($callback_data);
        $feedback_text = 'Обработка запроса...';
        if ($p['ok']) {
            if ($p['action'] === 'up' || $p['action'] === 'down') $feedback_text = 'Ваш голос принят!';
            elseif ($p['action'] === 'details') $feedback_text = 'Загружаю подробности...';
            elseif ($p['action'] === 'stats') $feedback_text = 'Собираю статистику...';
        }

        // Добавление в очередь
        $queue_entry = [
            'cb' => $callback_data,
            'fid' => (string) ($callback['from']['id'] ?? ''),
            'usr' => (string) ($callback['from']['username'] ?? ''),
            'cid' => (string) ($callback['message']['chat']['id'] ?? ''),
            'mid' => (string) ($callback['message']['message_id'] ?? ''),
            'time' => time()
        ];

        $fp = fopen($QUEUE_FILE, 'a');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, json_encode($queue_entry) . PHP_EOL);
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        // Продлеваем жизнь воркеру на каждое нажатие
        ga_trigger_worker_async($MODULE_ID, $data['cron_token']);

        // Моментальный ответ Telegram (снимает ожидание на кнопке)
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode([
            'method' => 'answerCallbackQuery',
            'callback_query_id' => $callback_id,
            'text' => $feedback_text
        ]));
    }
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true]));
}

if (isset($_GET['action']) && $_GET['action'] === 'run_worker') {
    if (($_GET['cron_token'] ?? '') !== $data['cron_token']) die("Forbidden");
    if (($data['bot_active'] ?? '0') !== '1') exit("Bot is disabled.");
    
    // Увеличиваем время работы для активной сессии
    @set_time_limit(150);
    ignore_user_abort(true);

    // Используем блокировку, чтобы не запустить два параллельных цикла
    $lock_file = DATA_DIR . $MODULE_ID . '_worker.lock';
    $fp_lock = fopen($lock_file, 'c+');
    if (!flock($fp_lock, LOCK_EX | LOCK_NB)) {
        // Если воркер уже запущен, "касаемся" файла, чтобы он продлил свою работу
        touch($lock_file);
        exit("Worker prolonged.");
    }

    $loop_start = time();
    $last_activity = time();
    $idle_timeout = 60; // Воркер активен 60 секунд после последнего действия
    $max_lifetime = 300; // Ограничение на один процесс 5 минут

    while (time() - $loop_start < $max_lifetime) {
        // Проверяем сигнал на продление жизни
        if (file_exists($lock_file) && (time() - filemtime($lock_file) < 5)) {
            $last_activity = time();
        }

        $jobs = [];
        $fp_q = fopen($QUEUE_FILE, 'c+');
        if ($fp_q && flock($fp_q, LOCK_EX)) {
            $raw = stream_get_contents($fp_q);
            if ($raw) {
                $lines = explode(PHP_EOL, trim($raw));
                foreach ($lines as $l) { if ($l) $jobs[] = json_decode($l, true); }
                ftruncate($fp_q, 0);
                $last_activity = time(); // Сброс таймера активности
            }
            flock($fp_q, LOCK_UN);
            fclose($fp_q);
        }

        if (!empty($jobs)) {
            $store = ga_feedback_load_store($FEEDBACK_FILE);
            foreach ($jobs as $job) {
                $p = ga_feedback_parse_callback_data($job['cb']);
                if (!$p['ok']) continue;
                $res = ga_feedback_store_apply_action($store, $data, $p['scope'], $p['action'], $p['trace'], $job['fid'], $job['usr'], $job['cid'], $job['mid']);
                $resp = ga_feedback_build_action_response($data, $res);
                if (in_array($p['action'], ['details', 'reasons', 'risks', 'stats', 'help'], true)) {
                    if (!empty($resp['full_text'])) ga_send_telegram($data['tg_token'], $job['cid'], $resp['full_text']);
                } elseif (in_array($p['action'], ['up', 'down'], true)) {
                    $markup = ga_build_feedback_markup($data, ['scope' => $p['scope'], 'trace' => $p['trace'], 'stats' => $res['stats']]);
                    if ($markup) ga_edit_telegram_reply_markup($data['tg_token'], $job['cid'], $job['mid'], $markup);
                }
            }
            ga_feedback_save_store($FEEDBACK_FILE, $store);
        }

        // Проверка на засыпание
        if (time() - $last_activity > $idle_timeout) break;

        usleep(500000); // Спим 0.5 сек между проверками очереди
    }

    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit("Worker finished (went to sleep)");
}

// Кнопка "Запустить систему сейчас" (включает главный тумблер и запускает первый пост)
if (isset($_GET['action']) && $_GET['action'] === 'start_now') {
    $run_id = 'start-now-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;

    $was_on = (($data['bot_active'] ?? '0') === '1');
    if (!$was_on) {
        $data['bot_active'] = '1';
        file_put_contents($DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        add_ga_log($LOGS_FILE, 'Система', 'success', 'Система включена кнопкой "Запустить сейчас".', $log_cfg);
    } else {
        add_ga_log($LOGS_FILE, 'Система', 'info', 'Нажата кнопка "Запустить сейчас" при включенной системе.', $log_cfg);
    }

    header('Location: ?module=' . urlencode($MODULE_ID) . '&action=force_run');
    exit;
}

// ГЛОБАЛЬНЫЙ ПРОЦЕСС "ПРОГОН МОНИТОРИНГА" 
if ((isset($_GET['action']) && ($_GET['action'] === 'force_run' || $_GET['action'] === 'test_run')) || isset($_GET['cron_token'])) {
    $run_id = 'run-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;

    // Защита если это вызов снаружи: проверяем токен
    $is_cron = isset($_GET['cron_token']);
    $is_manual_force = (!$is_cron && isset($_GET['action']) && $_GET['action'] === 'force_run');
    $is_test_run = (!$is_cron && isset($_GET['action']) && $_GET['action'] === 'test_run');
    $runtime_test_mode = (($data['test_mode'] ?? '0') === '1') || $is_test_run;
    if ($is_cron) {
        if ($_GET['cron_token'] !== $data['cron_token']) {
            die("Forbidden: Invalid Cron Token.");
        }
        // Проверка активности бота для крона
        if ($data['bot_active'] !== '1') {
            add_ga_log($LOGS_FILE, 'Система', 'info', 'Цикл пропущен: Бот отключен в настройках.', $log_cfg, ['reason' => 'bot_disabled', 'is_cron' => $is_cron ? '1' : '0']);
            die("Bot is disabled.");
        }
        if (ob_get_level() > 0) {
            ob_end_clean(); // отключаем админку если крон запрос
        }
    } else {
        // Главный тумблер: ручной запуск тоже блокируем, иначе "бот выключен" будет не настоящим.
        if (!$is_test_run && (($data['bot_active'] ?? '0') !== '1')) {
            add_ga_log($LOGS_FILE, 'Система', 'warning', 'Ручной запуск заблокирован: бот выключен в настройках.', $log_cfg, ['reason' => 'bot_disabled', 'manual_force' => $is_manual_force ? '1' : '0']);
            redirect_with_message('warning', 'Бот выключен. Включите тумблер "Система" и повторите.', ['module' => $MODULE_ID]);
        }
    }

    $errors = [];
    add_ga_log($LOGS_FILE, 'Система', 'info', 'Запуск цикла мониторинга...', $log_cfg, ['is_cron' => $is_cron ? '1' : '0', 'manual_force' => $is_manual_force ? '1' : '0', 'test_run' => $is_test_run ? '1' : '0', 'runtime_test_mode' => $runtime_test_mode ? '1' : '0', 'model' => $data['gemini_model']]);

    // 1. ЗАПРОС CommodityPriceAPI (XAU)
    if (empty($data['goldapi_key'])) {
        $errors[] = "Не указан API ключ CommodityPriceAPI";
    } else {
        $ch = curl_init("https://api.commoditypriceapi.com/v2/rates/latest?symbols=XAU");
        add_ga_log($LOGS_FILE, 'Парсинг', 'debug', 'Подготовка запроса к CommodityPriceAPI.', $log_cfg, [
            'connect_timeout' => (string) $data['curl_connect_timeout'],
            'timeout' => (string) $data['gold_timeout'],
            'ssl_verify' => (string) $data['curl_ssl_verify']
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "x-api-key: " . trim($data['goldapi_key']),
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $connect_timeout = max(3, min(60, (int) $data['curl_connect_timeout']));
        $gold_timeout = max(5, min(120, (int) $data['gold_timeout']));
        $ssl_verify = $data['curl_ssl_verify'] === '1';
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $gold_timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        $gold_json = curl_exec($ch);
        $gold_err = curl_error($ch);
        $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $gdata = json_decode($gold_json, true);
        $ok = is_array($gdata) && !empty($gdata['success']) && isset($gdata['rates']) && is_array($gdata['rates']) && isset($gdata['rates']['XAU']);
        if ($gold_json === false || $hc !== 200 || !$ok) {
            $gold_preview = is_string($gold_json) ? ga_mb_substr($gold_json, 0, 300) : '';
            $api_msg = '';
            if (is_array($gdata)) {
                $api_msg = (string) ($gdata['message'] ?? ($gdata['error']['message'] ?? ''));
            }
            add_ga_log($LOGS_FILE, 'Парсинг', 'error', "CommodityPriceAPI Ошибка HTTP $hc. cURL: {$gold_err}. Msg: {$api_msg}. Raw: {$gold_preview}", $log_cfg, ['http' => (string) $hc, 'curl_error' => $gold_err, 'api_message' => $api_msg, 'raw' => $gold_preview]);
            $errors[] = "Ошибка получения цены (CommodityPriceAPI)";
        } else {
            $xau = (float) $gdata['rates']['XAU'];
            $prev_price_raw = (string) ($data['last_price'] ?? '');
            $prev_price = is_numeric($prev_price_raw) ? (float) $prev_price_raw : 0.0;
            $ch = ($prev_price > 0) ? ($xau - $prev_price) : 0.0;
            $chp = ($prev_price > 0) ? (($ch / $prev_price) * 100.0) : 0.0;
            $meta = is_array($gdata['metadata']['XAU'] ?? null) ? $gdata['metadata']['XAU'] : [];
            $unit = (string) ($meta['unit'] ?? 'T.oz');
            $quote = (string) ($meta['quote'] ?? 'USD');
            $ts = (string) ($gdata['timestamp'] ?? '');
            add_ga_log($LOGS_FILE, 'Парсинг', 'success', "XAU Цена: {$xau} {$quote}/{$unit}", $log_cfg, ['price' => (string) $xau, 'unit' => $unit, 'quote' => $quote, 'timestamp' => $ts, 'prev_price' => (string) $prev_price, 'change' => (string) $ch, 'change_pct' => (string) $chp]);
            $gdata['computed'] = [
                'prev_price' => $prev_price,
                'ch' => $ch,
                'chp' => $chp
            ];
            $data['last_raw_gold'] = $gdata; // Сохраняем сырые данные CommodityPriceAPI (+ computed)
            $data['last_price'] = (string) $xau;
            $ctx_text = "Текущие данные CommodityPriceAPI:\n"
                . "Цена: {$xau} {$quote}/{$unit}\n"
                . "PrevPrice: {$prev_price}\n"
                . "Change: {$ch} ({$chp}%)\n"
                . "Timestamp: {$ts}\n"
                . "Time: " . date("Y-m-d H:i:s");
        }
    }

    // 2. ИИ АНАЛИЗ (Если включен)
    // Важно: "Test AI" может работать, а основной анализ нет, если systemInstruction игнорируется/не поддерживается.
    // Поэтому инструкции дублируем и в user-сообщении (contents).
    $msg_to_send = ($data['use_ai'] == '1')
        ? "Новые цены CommodityPriceAPI (AI включен, но ответ не получен):\n" . ($ctx_text ?? 'ошибка')
        : "Новые цены CommodityPriceAPI (без ИИ):\n" . ($ctx_text ?? 'ошибка');
    $signal = 'hold';

    if (empty($errors) && $data['use_ai'] == '1') {
        if (empty($data['gemini_key'])) {
            $errors[] = "Gemini API ключ не заполнен, но ИИ включен.";
        } else {
            add_ga_log($LOGS_FILE, 'AI Анализ', 'info', 'Отправка контекста к Gemini API...', $log_cfg, ['model' => $data['gemini_model']]);
            add_ga_log($LOGS_FILE, 'AI Анализ', 'debug', 'Параметры AI запроса.', $log_cfg, [
                'api_versions' => $data['gemini_api_versions'],
                'fallback_models' => $data['gemini_fallback_models'],
                'connect_timeout' => (string) $data['curl_connect_timeout'],
                'timeout' => (string) $data['ai_timeout'],
                'ssl_verify' => (string) $data['curl_ssl_verify'],
                'prompt_length' => (string) ga_mb_strlen((string) $data['prompt']),
                'context_length' => isset($ctx_text) ? (string) ga_mb_strlen((string) $ctx_text) : '0'
            ]);
            $ai_user_text = "ИНСТРУКЦИЯ (прочитай внимательно):\n"
                . (string) $data['prompt']
                . "\n\nДАННЫЕ:\n"
                . (string) $ctx_text
                . "\n\nВНИМАНИЕ: Верни СТРОГО полный JSON объект. Не возвращай пустой JSON {}. Обязательно заполни поля 'summary' и 'telegram_message'.";
            $ai_post = [
                "systemInstruction" => [
                    "parts" => [["text" => (string) $data['prompt']]]
                ],
                "contents" => [[
                    "role" => "user",
                    "parts" => [["text" => (string) $ai_user_text]]
                ]],
                "generationConfig" => [
                    "temperature" => 0.3,
                    "responseMimeType" => "application/json"
                ]
            ];
            $ai_call = ga_call_gemini($data['gemini_key'], $data['gemini_model'], $ai_post, [
                'timeout' => (int) $data['ai_timeout'],
                'connect_timeout' => (int) $data['curl_connect_timeout'],
                'ssl_verify' => $data['curl_ssl_verify'] === '1',
                'api_versions_csv' => $data['gemini_api_versions'],
                'fallback_models_csv' => $data['gemini_fallback_models'],
                'discover_models' => $data['gemini_discover_models'] === '1',
                'discovery_timeout' => (int) $data['gemini_discovery_timeout'],
                'use_bridge' => $data['use_bridge'],
                'bridge_url' => $data['bridge_url'],
                'bridge_secret' => $data['bridge_secret']
            ]);
            $ai_raw = $ai_call['raw'];
            $ai_err = $ai_call['err'];
            $ai_status = (int) $ai_call['status'];

            // Логируем сырой ответ для отладки
            add_ga_log($LOGS_FILE, 'AI Анализ', 'debug', 'Сырой ответ Gemini API.', $log_cfg, ['raw_response_preview' => ga_mb_substr((string) $ai_raw, 0, 500), 'status' => (string) $ai_status]);

            if ($ai_raw !== false && $ai_status === 200) {
                if (!empty($ai_call['model']) && $data['gemini_model'] !== $ai_call['model']) {
                    $data['gemini_model'] = $ai_call['model'];
                    add_ga_log($LOGS_FILE, 'AI Анализ', 'info', 'Автопереключение модели: ' . $ai_call['model'] . ' (' . $ai_call['api_version'] . ').', $log_cfg, ['model' => $ai_call['model'], 'api_version' => $ai_call['api_version']]);
                }
                $ai_j = json_decode($ai_raw, true) ?: [];
                $resp_text = '{}';
                
                // Логируем распарсенный JSON структуры ответа
                add_ga_log($LOGS_FILE, 'AI Анализ', 'debug', 'Структура ответа Gemini.', $log_cfg, ['has_candidates' => isset($ai_j['candidates']) ? '1' : '0', 'candidates_count' => isset($ai_j['candidates']) ? count($ai_j['candidates']) : '0', 'has_text_directly' => isset($ai_j['text']) ? '1' : '0']);
                
                // При использовании responseMimeType: application/json, Gemini может вернуть JSON напрямую
                // Проверяем несколько возможных форматов ответа
                $parts0 = $ai_j['candidates'][0]['content']['parts'] ?? [];
                if (is_array($parts0)) {
                    foreach ($parts0 as $p) {
                        if (is_array($p) && isset($p['text']) && trim((string) $p['text']) !== '') {
                            $resp_text = (string) $p['text'];
                            break;
                        }
                    }
                }
                
                // Если parts пуст или содержит только {}, пробуем получить JSON напрямую из ответа
                if ($resp_text === '{}' || $resp_text === '') {
                    // Проверяем, есть ли текст напрямую в candidates[0].content.text
                    $direct_text = $ai_j['candidates'][0]['content']['text'] ?? '';
                    if (!empty($direct_text)) {
                        $resp_text = (string) $direct_text;
                    }
                    // Если всё ещё пусто, возможно весь ответ - это JSON строка в первом candidate
                    if ($resp_text === '{}' || $resp_text === '') {
                        // Проверяем структуру ответа - иногда Gemini возвращает JSON без обёртки candidates
                        if (isset($ai_j['text'])) {
                            $resp_text = (string) $ai_j['text'];
                        } elseif (isset($ai_j['candidates']) && is_array($ai_j['candidates']) && !empty($ai_j['candidates'])) {
                            // Пробуем сериализовать содержимое candidate обратно
                            $candidate_content = $ai_j['candidates'][0]['content'] ?? null;
                            if (is_array($candidate_content) && !isset($candidate_content['parts'])) {
                                $resp_text = json_encode($candidate_content, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                }

                // Пытаемся выделить JSON-объект из текста (если модель добавила преамбулу).
                $resp_json_text = trim((string) $resp_text);
                $j1 = strpos($resp_json_text, '{');
                $j2 = strrpos($resp_json_text, '}');
                if ($j1 !== false && $j2 !== false && $j2 > $j1) {
                    $resp_json_text = substr($resp_json_text, $j1, $j2 - $j1 + 1);
                }

                $json_llm = json_decode($resp_json_text, true);

                $ai_summary = (is_array($json_llm) ? trim((string) ($json_llm['summary'] ?? ($json_llm['summary_text'] ?? ''))) : '');
                $ai_message = (is_array($json_llm) ? trim((string) ($json_llm['telegram_message'] ?? ($json_llm['message'] ?? ''))) : '');

                if (is_array($json_llm) && ($ai_summary !== '' || $ai_message !== '')) {
                    $data['last_raw_ai'] = $json_llm; // Сохраняем разобранный JSON от ИИ
                    if ($ai_message !== '') {
                        $msg_to_send = $ai_message;
                    }
                    if ($ai_summary === '' && $ai_message !== '') {
                        $derived = trim(strip_tags($ai_message));
                        $ai_summary = $derived !== '' ? ga_truncate_text($derived, 180) : 'AI: сводка не предоставлена.';
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI ответ принят, но summary отсутствует — использую derived summary.', $log_cfg);
                    }
                    $data['last_summary'] = $ai_summary;
                    $signal = ga_mb_strtolower((string) ($json_llm['signal_type'] ?? ($json_llm['signal'] ?? 'none')));
                    $imp = (int) ($json_llm['importance'] ?? 0);

                    add_ga_log($LOGS_FILE, 'AI Анализ', 'success', "AI ответ принят. Сигнал: {$signal}. Важность: $imp.", $log_cfg, ['signal' => (string) $signal, 'importance' => (string) $imp]);
                    add_ga_log($LOGS_FILE, 'AI Анализ', 'debug', 'Сырый ответ AI получен.', $log_cfg, ['raw' => ga_mb_substr((string) $ai_raw, 0, 400)]);

                    if (!$runtime_test_mode && $imp < (int) $data['threshold']) {
                        $errors[] = "STOP-сигнал: Важность ({$imp}) ниже порога ({$data['threshold']}). Игнор телеграм.";
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'info', "Слишком слабый сигнал, пост в телегу отклонен.", $log_cfg, ['importance' => (string) $imp, 'threshold' => (string) $data['threshold']]);
                    }

                } else {
                    $json_err = function_exists('json_last_error_msg') ? json_last_error_msg() : 'Unknown JSON error';
                    $reason = is_array($json_llm) ? 'missing_required_fields' : 'json_decode_failed';
                    add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'Gemini вернул JSON без обязательных полей. Пробую повторный запрос...', $log_cfg, ['reason' => $reason, 'json_error' => $json_err, 'raw' => ga_mb_substr($resp_json_text, 0, 250), 'runtime_test_mode' => $runtime_test_mode ? '1' : '0']);

                    // Single retry with stricter instruction (prevents "{}" empty object).
                    $retry_system = "FORCE SCHEMA: You MUST return a non-empty JSON object with 'summary' and 'telegram_message' fields. DO NOT return {}.\n" . (string) $data['prompt'];
                    $ai_user_text_retry = "ИНСТРУКЦИЯ (обязательно):\n"
                        . (string) $retry_system
                        . "\n\nДАННЫЕ:\n"
                        . (string) $ctx_text
                        . "\n\nВерни ТОЛЬКО JSON. Не возвращай пустой объект.";
                    $ai_post_retry = [
                        "systemInstruction" => [
                            "parts" => [["text" => $retry_system]]
                        ],
                        "contents" => [[
                            "role" => "user",
                            "parts" => [["text" => (string) $ai_user_text_retry]]
                        ]],
                        "generationConfig" => [
                            "temperature" => 0.2,
                            "responseMimeType" => "application/json"
                        ]
                    ];
                    $ai_call2 = ga_call_gemini($data['gemini_key'], $data['gemini_model'], $ai_post_retry, [
                        'timeout' => (int) $data['ai_timeout'],
                        'connect_timeout' => (int) $data['curl_connect_timeout'],
                        'ssl_verify' => $data['curl_ssl_verify'] === '1',
                        'api_versions_csv' => $data['gemini_api_versions'],
                        'fallback_models_csv' => $data['gemini_fallback_models'],
                        'discover_models' => $data['gemini_discover_models'] === '1',
                        'discovery_timeout' => (int) $data['gemini_discovery_timeout'],
                        'use_bridge' => $data['use_bridge'],
                        'bridge_url' => $data['bridge_url'],
                        'bridge_secret' => $data['bridge_secret']
                    ]);

                    $ai_raw2 = $ai_call2['raw'];
                    $ai_status2 = (int) $ai_call2['status'];
                    $ai_err2 = (string) ($ai_call2['err'] ?? '');
                    if ($ai_raw2 !== false && $ai_status2 === 200) {
                        $ai_j2 = json_decode((string) $ai_raw2, true) ?: [];
                        $resp_text2 = '{}';
                        
                        // Аналогичная логика для повторного запроса - проверяем разные форматы ответа
                        $parts02 = $ai_j2['candidates'][0]['content']['parts'] ?? [];
                        if (is_array($parts02)) {
                            foreach ($parts02 as $p2) {
                                if (is_array($p2) && isset($p2['text']) && trim((string) $p2['text']) !== '') {
                                    $resp_text2 = (string) $p2['text'];
                                    break;
                                }
                            }
                        }
                        
                        // Если parts пуст или содержит только {}, пробуем альтернативные источники
                        if ($resp_text2 === '{}' || $resp_text2 === '') {
                            $direct_text2 = $ai_j2['candidates'][0]['content']['text'] ?? '';
                            if (!empty($direct_text2)) {
                                $resp_text2 = (string) $direct_text2;
                            }
                            if ($resp_text2 === '{}' || $resp_text2 === '') {
                                if (isset($ai_j2['text'])) {
                                    $resp_text2 = (string) $ai_j2['text'];
                                } elseif (isset($ai_j2['candidates']) && is_array($ai_j2['candidates']) && !empty($ai_j2['candidates'])) {
                                    $candidate_content2 = $ai_j2['candidates'][0]['content'] ?? null;
                                    if (is_array($candidate_content2) && !isset($candidate_content2['parts'])) {
                                        $resp_text2 = json_encode($candidate_content2, JSON_UNESCAPED_UNICODE);
                                    }
                                }
                            }
                        }
                        
                        if ($resp_text2 === '{}' && !empty($parts02[0]) && is_array($parts02[0])) {
                            $enc2 = json_encode($parts02[0], JSON_UNESCAPED_UNICODE);
                            $resp_text2 = $enc2 !== false ? $enc2 : '{}';
                        }
                        $resp_json_text2 = $resp_text2;
                        $j1r = strpos($resp_json_text2, '{');
                        $j2r = strrpos($resp_json_text2, '}');
                        if ($j1r !== false && $j2r !== false && $j2r > $j1r) {
                            $resp_json_text2 = substr($resp_json_text2, $j1r, $j2r - $j1r + 1);
                        }
                        $json_llm2 = json_decode(trim((string)$resp_json_text2), true);
                        $ai_summary2 = (is_array($json_llm2) ? trim((string) ($json_llm2['summary'] ?? ($json_llm2['summary_text'] ?? ''))) : '');
                        $ai_message2 = (is_array($json_llm2) ? trim((string) ($json_llm2['telegram_message'] ?? ($json_llm2['message'] ?? ''))) : '');
                        if (is_array($json_llm2) && ($ai_summary2 !== '' || $ai_message2 !== '')) {
                            $data['last_raw_ai'] = $json_llm2;
                            if ($ai_message2 !== '') {
                                $msg_to_send = $ai_message2;
                            }
                            if ($ai_summary2 === '') {
                                $derived2 = trim(strip_tags($ai_message2));
                                $ai_summary2 = $derived2 !== '' ? ga_truncate_text($derived2, 180) : 'AI: сводка не предоставлена.';
                                add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'Повторный AI ответ принят, но summary отсутствует — использую derived summary.', $log_cfg);
                            }
                            $data['last_summary'] = $ai_summary2;
                            $signal = ga_mb_strtolower((string) ($json_llm2['signal_type'] ?? ($json_llm2['signal'] ?? 'none')));
                            $imp = (int) ($json_llm2['importance'] ?? 0);
                            add_ga_log($LOGS_FILE, 'AI Анализ', 'success', 'Повторный запрос к Gemini дал приемлемый JSON.', $log_cfg, ['importance' => (string) $imp, 'signal' => (string) $signal]);
                        } else {
                            $json_err2 = function_exists('json_last_error_msg') ? json_last_error_msg() : 'Unknown JSON error';
                            $lvl = $runtime_test_mode ? 'warning' : 'error';
                            $reason2 = is_array($json_llm2) ? 'missing_required_fields' : 'json_decode_failed';
                            add_ga_log($LOGS_FILE, 'AI Анализ', $lvl, 'Gemini повторно вернул невалидный формат.', $log_cfg, ['reason' => $reason2, 'json_error' => $json_err2, 'raw' => ga_mb_substr((string) $resp_json_text2, 0, 250), 'runtime_test_mode' => $runtime_test_mode ? '1' : '0']);
                            if ($runtime_test_mode) {
                                $data['last_raw_ai'] = [];
                                $data['last_summary'] = 'AI: невалидный JSON (повтор). Отправлено без AI.';
                                add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: невалидный JSON даже после повтора. Отправляю сообщение без AI.', $log_cfg);
                            } else {
                                $errors[] = "Gemini (AI) не смог собрать корректный JSON.";
                            }
                        }
                    } else {
                        $preview2 = is_string($ai_raw2) ? ga_mb_substr($ai_raw2, 0, 250) : '';
                        $lvl_retry_http = $runtime_test_mode ? 'warning' : 'error';
                        add_ga_log($LOGS_FILE, 'AI Анализ', $lvl_retry_http, "Повторный запрос к Gemini не удался. HTTP {$ai_status2}. cURL: {$ai_err2}. Raw: {$preview2}", $log_cfg, ['http' => (string) $ai_status2, 'curl_error' => $ai_err2, 'raw' => $preview2, 'runtime_test_mode' => $runtime_test_mode ? '1' : '0']);
                        if ($runtime_test_mode) {
                            $data['last_raw_ai'] = [];
                            $data['last_summary'] = 'AI: повторный запрос не удался. Отправлено без AI.';
                            add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: повторный запрос не удался. Отправляю сообщение без AI.', $log_cfg);
                        } else {
                            $errors[] = "Gemini (AI) не смог собрать корректный JSON.";
                        }
                    }
                }
            } else {
                $ai_preview = is_string($ai_raw) ? ga_mb_substr($ai_raw, 0, 400) : '';
                $attempts_preview = !empty($ai_call['attempts']) ? implode(' | ', array_slice($ai_call['attempts'], -4)) : '';
                $discovery_preview = !empty($ai_call['discovery']) ? implode(' | ', array_slice($ai_call['discovery'], -4)) : '';
                $error_type = (string) ($ai_call['error_type'] ?? ga_detect_gemini_error_type($ai_status, (string) $ai_raw));
                $lvl_ai_http = $runtime_test_mode ? 'warning' : 'error';
                add_ga_log($LOGS_FILE, 'AI Анализ', $lvl_ai_http, "Gemini HTTP Ошибка $ai_status. cURL: {$ai_err}. Raw: {$ai_preview}. Attempts: {$attempts_preview}. Discovery: {$discovery_preview}", $log_cfg, ['http' => (string) $ai_status, 'curl_error' => $ai_err, 'attempts' => $attempts_preview, 'discovery' => $discovery_preview, 'error_type' => $error_type, 'raw' => $ai_preview, 'runtime_test_mode' => $runtime_test_mode ? '1' : '0']);
                $last_try = (!empty($ai_call['model']) ? ' (' . ($ai_call['api_version'] ?: '?') . '/' . $ai_call['model'] . ')' : '');
                if ($error_type === 'location_not_supported') {
                    if ($runtime_test_mode) {
                        $data['last_raw_ai'] = [];
                        $data['last_summary'] = 'AI: location_not_supported. Отправлено без AI.';
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: location_not_supported. Отправляю сообщение без AI.', $log_cfg);
                    } else {
                        $errors[] = "Gemini недоступен для локации сервера/аккаунта (FAILED_PRECONDITION, HTTP {$ai_status}){$last_try}. Это региональное ограничение Google, а не ошибка вашего кода.";
                    }
                } elseif ($error_type === 'auth_or_permission') {
                    if ($runtime_test_mode) {
                        $data['last_raw_ai'] = [];
                        $data['last_summary'] = 'AI: auth_or_permission. Отправлено без AI.';
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: доступ отклонен. Отправляю сообщение без AI.', $log_cfg);
                    } else {
                        $errors[] = "Gemini отклонил доступ (ключ/API-права) HTTP {$ai_status}{$last_try}.";
                    }
                } elseif ($error_type === 'rate_limited') {
                    if ($runtime_test_mode) {
                        $data['last_raw_ai'] = [];
                        $data['last_summary'] = 'AI: rate_limited. Отправлено без AI.';
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: rate limit/quota. Отправляю сообщение без AI.', $log_cfg);
                    } else {
                        $errors[] = "Gemini ограничил запросы (quota/rate limit) HTTP {$ai_status}{$last_try}.";
                    }
                } else {
                    if ($runtime_test_mode) {
                        $data['last_raw_ai'] = [];
                        $data['last_summary'] = 'AI: ошибка Gemini. Отправлено без AI.';
                        add_ga_log($LOGS_FILE, 'AI Анализ', 'warning', 'AI отключен для этого запуска: ошибка Gemini. Отправляю сообщение без AI.', $log_cfg);
                    } else {
                        $errors[] = "Ошибка на стороне Google Gemini (HTTP {$ai_status}){$last_try}";
                    }
                }
            }
        }
    } else {
        $data['last_summary'] = 'Системное авто-обновление API (без AI).';
    }

    // 3. ОТПРАВКА TELEGRAM
    if (empty($errors)) {
        // Если тестовый режим - добавляем пометку
        if ($runtime_test_mode) {
            $msg_to_send = "⚠️ <b>[TEST MODE ACTIVE]</b>\n" . $msg_to_send;
        }

        if (empty($data['tg_token']) || empty($data['tg_chat_id'])) {
            add_ga_log($LOGS_FILE, 'Telegram', 'error', 'Данные для Телеграм не заданы в настройках.', $log_cfg, ['has_token' => empty($data['tg_token']) ? '0' : '1', 'has_chat_id' => empty($data['tg_chat_id']) ? '0' : '1']);
            $errors[] = "Telegram не настроен: заполните bot token и chat id.";
        } else {
            $feedback_scope = $runtime_test_mode ? 'test' : 'prod';
            $feedback_trace = substr(md5($run_id . '|' . $feedback_scope . '|' . $signal . '|' . (string) ($data['last_price'] ?? '')), 0, 10);
            $ai_ctx = is_array($data['last_raw_ai'] ?? null) ? $data['last_raw_ai'] : [];
            $feedback_context = [
                'run_id' => $run_id,
                'signal_type' => $signal,
                'confidence' => (int) ($ai_ctx['confidence'] ?? 0),
                'importance' => (int) ($ai_ctx['importance'] ?? 0),
                'summary' => (string) ($data['last_summary'] ?? ''),
                'price' => (string) ($data['last_price'] ?? ''),
                'reasoning' => is_array($ai_ctx['reasoning'] ?? null) ? $ai_ctx['reasoning'] : [],
                'risks' => is_array($ai_ctx['risks'] ?? null) ? $ai_ctx['risks'] : [],
                'telegram_message' => (string) $msg_to_send
            ];
            $feedback_markup = ga_build_feedback_markup($data, [
                'scope' => $feedback_scope,
                'run_id' => $run_id,
                'signal' => $signal,
                'trace' => $feedback_trace
            ]);
            if ($feedback_markup) {
                ga_feedback_register_context($FEEDBACK_FILE, $data, $feedback_scope, $feedback_trace, $feedback_context);
            }
            if ($feedback_markup && $data['feedback_auto_webhook'] === '1') {
                $feedback_webhook_url = ga_build_feedback_webhook_url($MODULE_ID, $data);
                $ensure = ga_ensure_feedback_webhook($data['tg_token'], $feedback_webhook_url, [
                    'connect_timeout' => (int) $data['curl_connect_timeout'],
                    'timeout' => (int) $data['tg_timeout'],
                    'ssl_verify' => $data['curl_ssl_verify'] === '1'
                ]);
                if ($ensure['ok']) {
                    add_ga_log($LOGS_FILE, 'Feedback', 'info', $ensure['changed'] ? 'Webhook для feedback автоматически переустановлен.' : 'Webhook для feedback уже настроен.', $log_cfg, [
                        'changed' => $ensure['changed'] ? '1' : '0',
                        'url' => $feedback_webhook_url
                    ]);
                } else {
                    $set_info = is_array($ensure['set'] ?? null) ? $ensure['set'] : [];
                    add_ga_log($LOGS_FILE, 'Feedback', 'warning', 'Не удалось автонастроить webhook для feedback. Кнопки отправлены без callback.', $log_cfg, [
                        'url' => $feedback_webhook_url,
                        'get_info_http' => (string) (($ensure['info']['http'] ?? 0)),
                        'set_http' => (string) (($set_info['http'] ?? 0)),
                        'set_error' => (string) ($set_info['err'] ?? ''),
                        'set_raw' => (string) ($set_info['raw'] ?? '')
                    ]);
                    $feedback_markup = null;
                }
            }
            $tg_res = ga_send_telegram($data['tg_token'], $data['tg_chat_id'], $msg_to_send, [
                'connect_timeout' => (int) $data['curl_connect_timeout'],
                'timeout' => (int) $data['tg_timeout'],
                'ssl_verify' => $data['curl_ssl_verify'] === '1',
                'reply_markup' => $feedback_markup
            ]);
            if ($tg_res['ok']) {
                add_ga_log($LOGS_FILE, 'Telegram', 'success', 'Сообщение в телеграм канал успешно ушло.', $log_cfg, ['http' => (string) ($tg_res['http'] ?? 0), 'feedback_buttons' => $feedback_markup ? '1' : '0']);
            } else {
                add_ga_log($LOGS_FILE, 'Telegram', 'error', 'API TG HTTP ' . (int) ($tg_res['http'] ?? 0) . ': ' . ($tg_res['err'] ?: $tg_res['raw']), $log_cfg, ['http' => (string) ($tg_res['http'] ?? 0), 'curl_error' => (string) ($tg_res['err'] ?? ''), 'raw' => (string) ($tg_res['raw'] ?? '')]);
                $errors[] = "Ошибка Telegram: HTTP " . (int) ($tg_res['http'] ?? 0) . ". Проверьте логи.";
            }
        }
    }

    // 4. ГЕНЕРАЦИЯ FRONT-END HTML ВНУТРЬ САЙТА
    $t_upd = date("H:i, d M Y");
    $price = number_format(floatval(str_replace(',', '', $data['last_price'])), 2, '.', ',');

    // Переменные отступов для чистого вывода в index.php
    $I_4 = "\n    ";
    $I_8 = "\n        ";

    $html = $I_4 . "<div class=\"xau-widget\">" .
        $I_8 . "<h3>Спотовая Цена <b>XAU / USD</b></h3>" .
        $I_8 . "<div class=\"xau-price\">\$" . h($price) . " <span style='font-size:12px;color:#aaa'>Оз</span></div>" .
        $I_8 . "<div class=\"xau-summary\">Аналитика: " . h($data['last_summary']) . "</div>" .
        $I_8 . "<small style='display:block;margin-top:10px;opacity:0.6'>Обновлено: {$t_upd}</small>" .
        $I_4 . "</div>";

    if (!empty($data['module_css'])) {
        $html .= $I_4 . "<style>" . $data['module_css'] . "</style>";
    }
    if (!empty($data['module_js'])) {
        $html .= $I_4 . "<script>" . $data['module_js'] . "</script>";
    }

    file_put_contents($DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $update_res = update_target_file($TARGET_FILE, $START, $END, $html);

    if ($is_cron) {
        echo json_encode(["status" => "cron executed", "run_id" => $run_id, "file_updated" => $update_res, "errs" => $errors], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $final_m = empty($errors) ? 'Цикл успешен! Записано и в Телеграм ушло.' : 'Цикл сработал с проблемами. Смотри логи.';
        redirect_with_message(empty($errors) ? 'success' : 'warning', $final_m, ['module' => $MODULE_ID]);
    }
}

// ЭКШН: ТЕСТ ТЕЛЕГРАМА
if (isset($_GET['action']) && $_GET['action'] === 'clear_logs') {
    @file_put_contents($LOGS_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    redirect_with_message('success', 'Журнал логов очищен.', ['module' => $MODULE_ID]);
}

if (isset($_GET['action']) && $_GET['action'] === 'clear_feedback_stats') {
    @file_put_contents($FEEDBACK_FILE, json_encode(['updated_at' => date('Y-m-d H:i:s'), 'traces' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    redirect_with_message('success', 'Статистика feedback-кнопок очищена.', ['module' => $MODULE_ID]);
}

if (isset($_GET['action']) && $_GET['action'] === 'check_feedback_webhook') {
    $run_id = 'check-webhook-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;

    if (empty($data['tg_token'])) {
        redirect_with_message('error', 'Сначала заполните Telegram токен.', ['module' => $MODULE_ID]);
    }

    $expected_url = ga_build_feedback_webhook_url($MODULE_ID, $data);
    $info = ga_get_telegram_webhook_info($data['tg_token'], [
        'connect_timeout' => (int) $data['curl_connect_timeout'],
        'timeout' => (int) $data['tg_timeout'],
        'ssl_verify' => $data['curl_ssl_verify'] === '1'
    ]);

    if (!$info['ok']) {
        add_ga_log($LOGS_FILE, 'Feedback', 'error', 'Не удалось получить getWebhookInfo.', $log_cfg, [
            'http' => (string) ($info['http'] ?? 0),
            'curl_error' => (string) ($info['err'] ?? ''),
            'raw' => (string) ($info['raw'] ?? '')
        ]);
        redirect_with_message('error', 'Ошибка getWebhookInfo. Проверьте логи.', ['module' => $MODULE_ID]);
    }

    $current_url = trim((string) ($info['result']['url'] ?? ''));
    $pending = (int) ($info['result']['pending_update_count'] ?? 0);
    $last_error_date = trim((string) ($info['result']['last_error_date'] ?? ''));
    $last_error_msg = trim((string) ($info['result']['last_error_message'] ?? ''));
    $is_match = ($current_url !== '' && $current_url === $expected_url);

    add_ga_log($LOGS_FILE, 'Feedback', $is_match ? 'success' : 'warning', $is_match ? 'Webhook проверен: URL совпадает.' : 'Webhook проверен: URL отличается от ожидаемого.', $log_cfg, [
        'expected_url' => $expected_url,
        'current_url' => $current_url,
        'pending_updates' => (string) $pending,
        'last_error_date' => $last_error_date,
        'last_error_message' => $last_error_msg
    ]);

    if ($is_match) {
        $msg = 'Webhook OK. pending_update_count=' . $pending;
        if ($last_error_msg !== '') {
            $msg .= '; last_error=' . ga_truncate_text($last_error_msg, 120);
        }
        redirect_with_message('success', $msg, ['module' => $MODULE_ID]);
    }

    redirect_with_message('error', 'Webhook не совпадает с ожидаемым URL. Нажмите "Переустановить Feedback Webhook".', ['module' => $MODULE_ID]);
}

if (isset($_GET['action']) && $_GET['action'] === 'setup_feedback_webhook') {
    $run_id = 'setup-webhook-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;

    if (empty($data['tg_token'])) {
        redirect_with_message('error', 'Сначала заполните Telegram токен.', ['module' => $MODULE_ID]);
    }

    $feedback_webhook_url = ga_build_feedback_webhook_url($MODULE_ID, $data);
    $set = ga_set_telegram_webhook($data['tg_token'], $feedback_webhook_url, [
        'connect_timeout' => (int) $data['curl_connect_timeout'],
        'timeout' => (int) $data['tg_timeout'],
        'ssl_verify' => $data['curl_ssl_verify'] === '1'
    ]);

    if ($set['ok']) {
        add_ga_log($LOGS_FILE, 'Feedback', 'success', 'Feedback webhook успешно установлен.', $log_cfg, [
            'url' => $feedback_webhook_url,
            'http' => (string) ($set['http'] ?? 0)
        ]);
        redirect_with_message('success', 'Feedback webhook установлен. Кнопки должны отвечать сразу.', ['module' => $MODULE_ID]);
    } else {
        add_ga_log($LOGS_FILE, 'Feedback', 'error', 'Не удалось установить feedback webhook.', $log_cfg, [
            'url' => $feedback_webhook_url,
            'http' => (string) ($set['http'] ?? 0),
            'curl_error' => (string) ($set['err'] ?? ''),
            'raw' => (string) ($set['raw'] ?? '')
        ]);
        redirect_with_message('error', 'Не удалось установить feedback webhook. Проверьте логи.', ['module' => $MODULE_ID]);
    }
}

// ЭКШН: ТЕСТ ТЕЛЕГРАМА
if (isset($_GET['action']) && $_GET['action'] === 'test_tg') {
    $run_id = 'test-tg-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;
    if (empty($data['tg_token']) || empty($data['tg_chat_id'])) {
        redirect_with_message('error', 'TG Токен или Chat ID не заполнены!', ['module' => $MODULE_ID]);
    }
    $test_trace = substr(md5($run_id . '|manual_test|manual'), 0, 10);
    $feedback_markup = ga_build_feedback_markup($data, [
        'scope' => 'manual_test',
        'run_id' => $run_id,
        'signal' => 'manual',
        'trace' => $test_trace
    ]);
    if ($feedback_markup) {
        ga_feedback_register_context($FEEDBACK_FILE, $data, 'manual_test', $test_trace, [
            'run_id' => $run_id,
            'signal_type' => 'manual',
            'confidence' => 0,
            'importance' => 0,
            'summary' => 'Тестовая отправка',
            'price' => (string) ($data['last_price'] ?? ''),
            'reasoning' => ['Проверка связки Telegram webhook + callback'],
            'risks' => [],
            'telegram_message' => 'Тестовое сообщение'
        ]);
    }
    if ($feedback_markup && $data['feedback_auto_webhook'] === '1') {
        $feedback_webhook_url = ga_build_feedback_webhook_url($MODULE_ID, $data);
        $ensure = ga_ensure_feedback_webhook($data['tg_token'], $feedback_webhook_url, [
            'connect_timeout' => (int) $data['curl_connect_timeout'],
            'timeout' => (int) $data['tg_timeout'],
            'ssl_verify' => $data['curl_ssl_verify'] === '1'
        ]);
        if (!$ensure['ok']) {
            $set_info = is_array($ensure['set'] ?? null) ? $ensure['set'] : [];
            add_ga_log($LOGS_FILE, 'Feedback', 'warning', 'Автонастройка feedback webhook не удалась в test_tg. Кнопки отключены для этого теста.', $log_cfg, [
                'url' => $feedback_webhook_url,
                'set_http' => (string) (($set_info['http'] ?? 0)),
                'set_error' => (string) ($set_info['err'] ?? ''),
                'set_raw' => (string) ($set_info['raw'] ?? '')
            ]);
            $feedback_markup = null;
        }
    }
    $res = ga_send_telegram($data['tg_token'], $data['tg_chat_id'], "🔔 <b>Тестовое сообщение</b>\nСвязь с ботом настроена корректно. Модуль Gold Analyzer готов к работе!", [
        'connect_timeout' => (int) $data['curl_connect_timeout'],
        'timeout' => (int) $data['tg_timeout'],
        'ssl_verify' => $data['curl_ssl_verify'] === '1',
        'reply_markup' => $feedback_markup
    ]);
    if ($res['ok']) {
        add_ga_log($LOGS_FILE, 'Telegram', 'success', 'Тестовое сообщение отправлено вручную пользователем.', $log_cfg, ['http' => (string) ($res['http'] ?? 0), 'feedback_buttons' => $feedback_markup ? '1' : '0']);
        redirect_with_message('success', 'Telegram ✅ Тестовый сигнал успешно прошел!', ['module' => $MODULE_ID]);
    } else {
        add_ga_log($LOGS_FILE, 'Telegram', 'error', 'Ошибка теста (HTTP ' . (int) ($res['http'] ?? 0) . '): ' . ($res['err'] ?: $res['raw']), $log_cfg, ['http' => (string) ($res['http'] ?? 0), 'curl_error' => (string) ($res['err'] ?? ''), 'raw' => (string) ($res['raw'] ?? '')]);
        redirect_with_message('error', 'Ошибка отправки: ' . ($res['err'] ?: 'Проверьте логи'), ['module' => $MODULE_ID]);
    }
}

// ЭКШН: ТЕСТ AI (Gemini)
if (isset($_GET['action']) && $_GET['action'] === 'test_ai') {
    $run_id = 'test-ai-' . date('Ymd-His') . '-' . ga_random_hex(4);
    $log_cfg = $data;
    $log_cfg['run_id'] = $run_id;
    if (empty($data['gemini_key'])) {
        redirect_with_message('error', 'Gemini API ключ не заполнен!', ['module' => $MODULE_ID]);
    }

    add_ga_log($LOGS_FILE, 'AI Тест', 'info', 'Ручная проверка связи с Gemini...', $log_cfg, ['model' => $data['gemini_model']]);

    $ai_post = [
        "contents" => [["parts" => [["text" => "Ping. Respond with 'pong' and nothing else."]]]]
    ];
    $ai_call = ga_call_gemini($data['gemini_key'], $data['gemini_model'], $ai_post, [
        'timeout' => (int) $data['ai_timeout'],
        'connect_timeout' => (int) $data['curl_connect_timeout'],
        'ssl_verify' => $data['curl_ssl_verify'] === '1',
        'api_versions_csv' => $data['gemini_api_versions'],
        'fallback_models_csv' => $data['gemini_fallback_models'],
        'discover_models' => $data['gemini_discover_models'] === '1',
        'discovery_timeout' => (int) $data['gemini_discovery_timeout'],
        'use_bridge' => $data['use_bridge'],
        'bridge_url' => $data['bridge_url'],
        'bridge_secret' => $data['bridge_secret']
    ]);

    if ($ai_call['ok']) {
        if (!empty($ai_call['model']) && $data['gemini_model'] !== $ai_call['model']) {
            $data['gemini_model'] = $ai_call['model'];
            file_put_contents($DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            add_ga_log($LOGS_FILE, 'AI Тест', 'info', 'Автопереключение модели: ' . $ai_call['model'] . ' (' . $ai_call['api_version'] . ').', $log_cfg, ['model' => $ai_call['model'], 'api_version' => $ai_call['api_version']]);
        }
        add_ga_log($LOGS_FILE, 'AI Тест', 'success', 'Связь с Gemini установлена успешно. Модель: ' . ($ai_call['model'] ?: $data['gemini_model']), $log_cfg, ['model' => $ai_call['model'] ?: $data['gemini_model']]);
        redirect_with_message('success', 'Gemini AI ✅ Связь установлена успешно!', ['module' => $MODULE_ID]);
    } else {
        $status = (int) $ai_call['status'];
        $preview = is_string($ai_call['raw']) ? ga_mb_substr($ai_call['raw'], 0, 400) : '';
        $attempts_preview = !empty($ai_call['attempts']) ? implode(' | ', array_slice($ai_call['attempts'], -4)) : '';
        $discovery_preview = !empty($ai_call['discovery']) ? implode(' | ', array_slice($ai_call['discovery'], -4)) : '';
        $error_type = (string) ($ai_call['error_type'] ?? ga_detect_gemini_error_type($status, (string) ($ai_call['raw'] ?? '')));
        add_ga_log($LOGS_FILE, 'AI Тест', 'error', "Ошибка связи: HTTP $status. Raw: {$preview}. Err: {$ai_call['err']}. Attempts: {$attempts_preview}. Discovery: {$discovery_preview}", $log_cfg, ['http' => (string) $status, 'curl_error' => $ai_call['err'], 'attempts' => $attempts_preview, 'discovery' => $discovery_preview, 'error_type' => $error_type, 'raw' => $preview]);
        $last_try = (!empty($ai_call['model']) ? ' Последняя попытка: ' . ($ai_call['api_version'] ?: '?') . '/' . $ai_call['model'] . '.' : '');
        if ($error_type === 'location_not_supported') {
            redirect_with_message('error', "Локация сервера/аккаунта не поддерживается Gemini API (FAILED_PRECONDITION, HTTP $status)." . $last_try . " Проверьте логи.", ['module' => $MODULE_ID]);
        } elseif ($error_type === 'auth_or_permission') {
            redirect_with_message('error', "Доступ к Gemini отклонен (ключ/API-права, HTTP $status)." . $last_try . " Проверьте логи.", ['module' => $MODULE_ID]);
        } elseif ($error_type === 'rate_limited') {
            redirect_with_message('error', "Лимит запросов Gemini исчерпан (HTTP $status)." . $last_try . " Проверьте логи.", ['module' => $MODULE_ID]);
        } else {
            redirect_with_message('error', "Ошибка связи с ИИ (HTTP $status)." . $last_try . " Проверьте логи.", ['module' => $MODULE_ID]);
        }
    }
}

// СОХРАНЕНИЕ НАСТРОЕК 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_configs') {
    $data['bot_active'] = isset($_POST['bot_active']) ? '1' : '0';
    $data['test_mode'] = isset($_POST['test_mode']) ? '1' : '0';
    $data['goldapi_key'] = trim($_POST['goldapi_key']);
    $data['gemini_key'] = trim($_POST['gemini_key']);
    $data['tg_token'] = trim($_POST['tg_token']);
    $data['gemini_model'] = ga_normalize_gemini_model($_POST['gemini_model'] ?? '');
    if ($data['gemini_model'] === 'gemini-1.5-flash-8b') {
        $data['gemini_model'] = 'gemini-2.5-flash';
    }
    $data['tg_chat_id'] = trim($_POST['tg_chat_id']);
    $data['use_ai'] = isset($_POST['use_ai']) ? '1' : '0';
    $data['gemini_api_versions'] = trim((string) ($_POST['gemini_api_versions'] ?? $data['gemini_api_versions']));
    $data['gemini_fallback_models'] = trim((string) ($_POST['gemini_fallback_models'] ?? $data['gemini_fallback_models']));
    $data['gemini_discover_models'] = isset($_POST['gemini_discover_models']) ? '1' : '0';
    $data['gemini_discovery_timeout'] = (string) max(5, min(90, (int) ($_POST['gemini_discovery_timeout'] ?? $data['gemini_discovery_timeout'])));
    $data['curl_ssl_verify'] = isset($_POST['curl_ssl_verify']) ? '1' : '0';
    $data['curl_connect_timeout'] = (string) max(3, min(60, (int) ($_POST['curl_connect_timeout'] ?? $data['curl_connect_timeout'])));
    $data['gold_timeout'] = (string) max(5, min(120, (int) ($_POST['gold_timeout'] ?? $data['gold_timeout'])));
    $data['ai_timeout'] = (string) max(5, min(180, (int) ($_POST['ai_timeout'] ?? $data['ai_timeout'])));
    $data['tg_timeout'] = (string) max(5, min(120, (int) ($_POST['tg_timeout'] ?? $data['tg_timeout'])));
    $data['logging_enabled'] = isset($_POST['logging_enabled']) ? '1' : '0';
    $data['log_level'] = in_array($_POST['log_level'] ?? 'debug', ['error', 'warning', 'info', 'debug', 'trace'], true) ? $_POST['log_level'] : 'debug';
    $data['log_max_entries'] = (string) max(50, min(5000, (int) ($_POST['log_max_entries'] ?? $data['log_max_entries'])));
    $data['log_message_limit'] = (string) max(200, min(20000, (int) ($_POST['log_message_limit'] ?? $data['log_message_limit'])));
    $data['log_include_http_body'] = isset($_POST['log_include_http_body']) ? '1' : '0';
    $data['use_bridge'] = isset($_POST['use_bridge']) ? '1' : '0';
    $data['bridge_url'] = trim($_POST['bridge_url'] ?? '');
    $data['bridge_secret'] = trim($_POST['bridge_secret'] ?? '');
    $data['log_success_requests'] = isset($_POST['log_success_requests']) ? '1' : '0';
    $data['feedback_buttons_enabled'] = isset($_POST['feedback_buttons_enabled']) ? '1' : '0';
    $data['feedback_include_test'] = isset($_POST['feedback_include_test']) ? '1' : '0';
    $data['feedback_auto_webhook'] = isset($_POST['feedback_auto_webhook']) ? '1' : '0';
    $data['feedback_force_https'] = isset($_POST['feedback_force_https']) ? '1' : '0';
    $data['feedback_webhook_base_url'] = trim((string) ($_POST['feedback_webhook_base_url'] ?? $data['feedback_webhook_base_url']));
    $data['feedback_show_counters'] = isset($_POST['feedback_show_counters']) ? '1' : '0';
    $data['feedback_positive_text'] = trim((string) ($_POST['feedback_positive_text'] ?? $data['feedback_positive_text']));
    $data['feedback_negative_text'] = trim((string) ($_POST['feedback_negative_text'] ?? $data['feedback_negative_text']));
    $data['feedback_neutral_text'] = trim((string) ($_POST['feedback_neutral_text'] ?? $data['feedback_neutral_text']));
    $data['feedback_reasons_text'] = trim((string) ($_POST['feedback_reasons_text'] ?? $data['feedback_reasons_text']));
    $data['feedback_risks_text'] = trim((string) ($_POST['feedback_risks_text'] ?? $data['feedback_risks_text']));
    $data['feedback_stats_text'] = trim((string) ($_POST['feedback_stats_text'] ?? $data['feedback_stats_text']));
    $data['feedback_help_text'] = trim((string) ($_POST['feedback_help_text'] ?? $data['feedback_help_text']));
    $data['feedback_thanks_text'] = trim((string) ($_POST['feedback_thanks_text'] ?? $data['feedback_thanks_text']));
    $data['feedback_support_text'] = trim((string) ($_POST['feedback_support_text'] ?? $data['feedback_support_text']));
    $data['feedback_support_url'] = trim((string) ($_POST['feedback_support_url'] ?? $data['feedback_support_url']));
    $data['feedback_store_max_traces'] = (string) max(50, min(5000, (int) ($_POST['feedback_store_max_traces'] ?? $data['feedback_store_max_traces'])));
    $data['feedback_store_max_users'] = (string) max(100, min(20000, (int) ($_POST['feedback_store_max_users'] ?? $data['feedback_store_max_users'])));
    $legacy_tokens = ga_normalize_feedback_tokens($data['feedback_token_legacy'] ?? []);
    if (isset($_POST['regenerate_feedback_token']) && $_POST['regenerate_feedback_token'] === '1') {
        $old_token = trim((string) ($data['feedback_token'] ?? ''));
        if ($old_token !== '') {
            array_unshift($legacy_tokens, $old_token);
        }
        $legacy_tokens = array_slice(array_values(array_unique($legacy_tokens)), 0, 6);
        $data['feedback_token'] = ga_random_hex(12);
    }
    $data['feedback_token_legacy'] = $legacy_tokens;
    $threshold = (int) ($_POST['threshold'] ?? 70);
    $threshold = max(0, min(100, $threshold));
    $data['threshold'] = (string) $threshold;
    $data['prompt'] = trim($_POST['prompt']);
    $data['module_css'] = (string) ($_POST['module_css'] ?? '');
    $data['module_js'] = (string) ($_POST['module_js'] ?? '');

    file_put_contents($DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $save_log_cfg = $data;
    $save_log_cfg['run_id'] = 'save-' . date('Ymd-His');
    add_ga_log($LOGS_FILE, 'Система', 'info', 'Настройки модуля сохранены.', $save_log_cfg, [
        'model' => $data['gemini_model'],
        'log_level' => $data['log_level'],
        'ssl_verify' => $data['curl_ssl_verify'],
        'ai_timeout' => $data['ai_timeout'],
        'discover_models' => $data['gemini_discover_models'],
        'discovery_timeout' => $data['gemini_discovery_timeout'],
        'feedback_buttons_enabled' => $data['feedback_buttons_enabled'],
        'feedback_include_test' => $data['feedback_include_test'],
        'feedback_auto_webhook' => $data['feedback_auto_webhook'],
        'feedback_force_https' => $data['feedback_force_https'],
        'feedback_webhook_base_url' => $data['feedback_webhook_base_url'],
        'feedback_show_counters' => $data['feedback_show_counters'],
        'feedback_legacy_tokens' => (string) count($data['feedback_token_legacy'] ?? []),
        'feedback_store_max_traces' => $data['feedback_store_max_traces'],
        'feedback_store_max_users' => $data['feedback_store_max_users']
    ]);
    redirect_with_message('success', 'Все настройки модуля Gold сохранены', ['module' => $MODULE_ID]);
}

$CRON_URL = ga_build_module_url($MODULE_ID, ['cron_token' => $data['cron_token']]);
$FEEDBACK_WEBHOOK_URL = ga_build_feedback_webhook_url($MODULE_ID, $data);

// Export data for "tunnel" helper module (copy/paste flow).
$GA_TUNNEL_EXPORT_PAYLOAD = [
    'type' => 'ga_tunnel_export',
    'version' => 1,
    'generated_at' => date('c'),
    'module_id' => (string) $MODULE_ID,
    'tg_token' => (string) ($data['tg_token'] ?? ''),
    'webhook' => [
        'action' => 'tg_feedback',
        'params' => [
            'feedback_token' => (string) ($data['feedback_token'] ?? ''),
        ],
        'force_https' => (($data['feedback_force_https'] ?? '1') === '1'),
        'base_url_hint' => (string) ($data['feedback_webhook_base_url'] ?? ''),
    ],
    'apply' => [
        'feedback_webhook_base_url_key' => 'feedback_webhook_base_url',
        'feedback_force_https_key' => 'feedback_force_https',
    ],
];
$GA_TUNNEL_EXPORT_JSON = json_encode($GA_TUNNEL_EXPORT_PAYLOAD, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$GA_TUNNEL_EXPORT = 'GA_TUNNEL_V1:' . rtrim(strtr(base64_encode($GA_TUNNEL_EXPORT_JSON ?: '{}'), '+/', '-_'), '=');

$modlogs = get_ga_logs($LOGS_FILE);

$ga_show_legacy_ui = isset($_GET['legacy_ui']) && (string) $_GET['legacy_ui'] === '1';

$ga_is_bot_on = ($data['bot_active'] ?? '0') === '1';
$ga_is_test = ($data['test_mode'] ?? '0') === '1';
$ga_is_ai = ($data['use_ai'] ?? '0') === '1';
$ga_is_bridge = ($data['use_bridge'] ?? '0') === '1';
$ga_is_feedback = ($data['feedback_buttons_enabled'] ?? '0') === '1';

$ga_missing = [];
if (trim((string) ($data['goldapi_key'] ?? '')) === '') $ga_missing[] = 'CommodityPriceAPI key';
if (trim((string) ($data['tg_token'] ?? '')) === '') $ga_missing[] = 'TG token';
if (trim((string) ($data['tg_chat_id'] ?? '')) === '') $ga_missing[] = 'TG chat_id';
if ($ga_is_ai && trim((string) ($data['gemini_key'] ?? '')) === '') $ga_missing[] = 'Gemini key';

$ga_worker_url = ga_build_module_url($MODULE_ID, ['action' => 'run_worker', 'cron_token' => $data['cron_token']]);
?>

<?php if (!$ga_show_legacy_ui): ?>
    <div class="ga-admin">
        <div class="ga-topbar">
            <div>
                <div class="ga-title">Gold Analyzer • XAU мониторинг</div>
                <div class="ga-chips">
                    <span class="ga-chip <?php echo $ga_is_bot_on ? 'ok' : 'bad'; ?>"><?php echo $ga_is_bot_on ? 'Система ON' : 'Система OFF'; ?></span>
                    <span class="ga-chip <?php echo $ga_is_test ? 'warn' : 'ok'; ?>"><?php echo $ga_is_test ? 'Тестовый режим' : 'Рабочий режим'; ?></span>
                    <span class="ga-chip <?php echo $ga_is_ai ? 'ok' : 'muted'; ?>"><?php echo $ga_is_ai ? 'AI включен' : 'AI выключен'; ?></span>
                    <span class="ga-chip <?php echo $ga_is_bridge ? 'ok' : 'muted'; ?>"><?php echo $ga_is_bridge ? 'Bridge ON' : 'Bridge OFF'; ?></span>
                    <span class="ga-chip <?php echo $ga_is_feedback ? 'ok' : 'muted'; ?>"><?php echo $ga_is_feedback ? 'Feedback ON' : 'Feedback OFF'; ?></span>
                    <?php if (!empty($ga_missing)): ?>
                        <span class="ga-chip bad">Не заполнено: <?php echo h(implode(', ', $ga_missing)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ga-actions">
                <?php if (!$ga_is_bot_on): ?>
                    <a href="?module=<?php echo $MODULE_ID; ?>&action=start_now" class="btn btn-primary ga-btn-strong">▶ Запустить сейчас</a>
                <?php else: ?>
                    <a href="?module=<?php echo $MODULE_ID; ?>&action=force_run" class="btn btn-primary ga-btn-strong">⚡ RUN сейчас</a>
                <?php endif; ?>
                <a href="?module=<?php echo $MODULE_ID; ?>&action=test_run" class="btn ga-btn">🧪 Тестовый RUN</a>
                <a href="?module=<?php echo $MODULE_ID; ?>&action=test_tg" class="btn ga-btn">🧪 Test TG</a>
                <a href="?module=<?php echo $MODULE_ID; ?>&action=test_ai" class="btn ga-btn">🧪 Test AI</a>
                <a href="?module=<?php echo $MODULE_ID; ?>&action=clear_logs" class="btn ga-btn">🗑 Очистить логи</a>
                <a href="?module=<?php echo $MODULE_ID; ?>&legacy_ui=1" class="btn ga-btn ga-btn-ghost">Legacy UI</a>
            </div>
        </div>

        <div class="card ga-card">
            <div class="card-head ga-head">🚀 Быстрый старт</div>
            <div class="card-body ga-body">
                <div class="ga-grid2">
                    <div class="ga-box">
                        <div class="ga-box-title">1) Заполни ключи и Telegram</div>
                        <div class="ga-box-text">
                            CommodityPriceAPI → цена XAU<br>
                            Telegram → token + chat_id<br>
                            (Если AI включен) Gemini → key + модель
                        </div>
                    </div>
                    <div class="ga-box">
                        <div class="ga-box-title">2) Проверь связку</div>
                        <div class="ga-box-text">
                            <b>Test TG</b> → тестовый пост в канал<br>
                            <b>Test AI</b> → успех или понятная ошибка в логах<br>
                            <b>Тестовый RUN</b> → прогон цены+AI+Telegram (разово, с пометкой теста)<br>
                            <b>Запустить сейчас</b> → включит систему и отправит первый пост сразу
                        </div>
                    </div>
                </div>

                <div class="ga-grid2 ga-mt">
                    <div class="ga-box">
                        <div class="ga-box-title">3) Cron (мониторинг)</div>
                        <div class="ga-box-text ga-dim">
                            <?php echo ($data['test_mode'] === '1') ? '<b class="ga-warn">ТЕСТОВЫЙ:</b> раз в минуту.' : '<b class="ga-ok">РАБОЧИЙ:</b> раз в 12 часов.'; ?>
                        </div>
                        <div class="ga-code">
                            <code id="ga_cron_cmd">curl -L "<?php echo $CRON_URL; ?>"</code>
                            <button type="button" class="btn ga-btn ga-copy" data-ga-copy="#ga_cron_cmd">Скопировать</button>
                        </div>
                    </div>
                    <div class="ga-box">
                        <div class="ga-box-title">4) Cron (feedback очередь)</div>
                        <div class="ga-code">
                            <code id="ga_worker_cmd">curl -L "<?php echo $ga_worker_url; ?>"</code>
                            <button type="button" class="btn ga-btn ga-copy" data-ga-copy="#ga_worker_cmd">Скопировать</button>
                        </div>
                        <div class="ga-box-text ga-dim ga-mt-sm">
                            Shared-хостинг:<br>
                            <span class="ga-inlinecode" id="ga_worker_cron">*/1 * * * * curl -L "<?php echo $ga_worker_url; ?>" &gt; /dev/null 2&gt;&amp;1</span>
                            <button type="button" class="btn ga-btn ga-copy ga-ml" data-ga-copy="#ga_worker_cron">Скопировать</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ga-layout">
            <div class="ga-form">
                <form method="POST" class="app-form">
                    <input type="hidden" name="action" value="save_configs">

                    <div class="card ga-card ga-mt">
                        <div class="card-head ga-head">⚙️ Основное</div>
                        <div class="card-body ga-body">
                            <div class="ga-grid3">
                                <label class="ga-toggle">
                                    <span>🚦 Система (главный)</span>
                                    <span class="mod-switch">
                                        <input type="checkbox" name="bot_active" value="1" <?php echo ($data['bot_active'] == '1') ? 'checked' : ''; ?>>
                                        <span class="mod-switch-slider"></span>
                                    </span>
                                </label>
                                <label class="ga-toggle">
                                    <span>🧪 Тест режим</span>
                                    <span class="mod-switch">
                                        <input type="checkbox" name="test_mode" value="1" <?php echo ($data['test_mode'] == '1') ? 'checked' : ''; ?>>
                                        <span class="mod-switch-slider"></span>
                                    </span>
                                </label>
                                <label class="ga-toggle">
                                    <span>✨ Gemini AI</span>
                                    <span class="mod-switch">
                                        <input type="checkbox" name="use_ai" value="1" <?php echo ($data['use_ai'] == '1') ? 'checked' : ''; ?>>
                                        <span class="mod-switch-slider"></span>
                                    </span>
                                </label>
                            </div>
                            <div class="ga-help ga-mt-sm">
                                <b>Система OFF</b> блокирует cron-цикл и рабочий <b>RUN</b> (и отправку в Telegram). Кнопки <b>Test TG</b>/<b>Test AI</b>/<b>Тестовый RUN</b> — для диагностики и могут запускаться вручную.
                            </div>
                        </div>
                    </div>

                    <div class="card ga-card ga-mt">
                        <div class="card-head ga-head">🔑 API и Telegram</div>
                        <div class="card-body ga-body">
                            <div class="ga-grid2">
                                <div>
                                    <label>CommodityPriceAPI key</label>
                                    <input type="password" name="goldapi_key" value="<?php echo h($data['goldapi_key']); ?>" placeholder="e16ce0ea-c213-...">
                                    <div class="ga-hint">Ключ нужен для цены XAU. В код не вшивается.</div>
                                </div>
                                <div>
                                    <label>Telegram chat_id (канал/группа)</label>
                                    <input type="text" name="tg_chat_id" value="<?php echo h($data['tg_chat_id']); ?>" placeholder="-1001234567890">
                                    <div class="ga-hint">Часто начинается на <code>-100</code>.</div>
                                </div>
                                <div>
                                    <label>Telegram bot token</label>
                                    <input type="password" name="tg_token" value="<?php echo h($data['tg_token']); ?>" placeholder="123456:ABC...">
                                    <div class="ga-hint">Боту нужны права писать в канал/группу.</div>
                                </div>
                                <div class="ga-box ga-box-compact">
                                    <div class="ga-box-title">Быстрые тесты</div>
                                    <div class="ga-btnrow">
                                        <a href="?module=<?php echo $MODULE_ID; ?>&action=test_tg" class="btn ga-btn">🧪 Test TG</a>
                                        <a href="?module=<?php echo $MODULE_ID; ?>&action=test_ai" class="btn ga-btn">🧪 Test AI</a>
                                        <a href="?module=<?php echo $MODULE_ID; ?>&action=test_run" class="btn ga-btn">🧪 Тестовый RUN</a>
                                        <?php if (!$ga_is_bot_on): ?>
                                            <a href="?module=<?php echo $MODULE_ID; ?>&action=start_now" class="btn ga-btn-strong">▶ START</a>
                                        <?php else: ?>
                                            <a href="?module=<?php echo $MODULE_ID; ?>&action=force_run" class="btn ga-btn-strong">⚡ RUN</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <details class="ga-details ga-mt" open>
                        <summary class="ga-summary">💬 Feedback-кнопки (Telegram)</summary>
                        <div class="card ga-card">
                            <div class="card-body ga-body">
                                <div class="ga-grid2">
                                    <div>
                                        <label class="ga-inline"><input type="checkbox" name="feedback_buttons_enabled" <?php echo ($data['feedback_buttons_enabled'] == '1') ? 'checked' : ''; ?>>Включить inline-кнопки</label>
                                        <label class="ga-inline"><input type="checkbox" name="feedback_include_test" <?php echo ($data['feedback_include_test'] == '1') ? 'checked' : ''; ?>>Показывать и в тестовом режиме</label>
                                        <label class="ga-inline"><input type="checkbox" name="feedback_auto_webhook" <?php echo ($data['feedback_auto_webhook'] == '1') ? 'checked' : ''; ?>>Автонастройка webhook перед отправкой</label>
                                        <label class="ga-inline"><input type="checkbox" name="feedback_show_counters" <?php echo ($data['feedback_show_counters'] == '1') ? 'checked' : ''; ?>>Счетчики на кнопках</label>
                                        <label class="ga-inline"><input type="checkbox" name="feedback_force_https" <?php echo ($data['feedback_force_https'] == '1') ? 'checked' : ''; ?>>Принудительно HTTPS</label>
                                    </div>
                                    <div>
                                        <label>Webhook Base URL (если прокси ломает авто-URL)</label>
                                        <input type="text" name="feedback_webhook_base_url" value="<?php echo h($data['feedback_webhook_base_url']); ?>" placeholder="https://example.com/admin.php">
                                        <div class="ga-btnrow ga-mt-sm">
                                            <a href="?module=<?php echo $MODULE_ID; ?>&action=setup_feedback_webhook" class="btn ga-btn">⚙️ Переустановить webhook</a>
                                            <a href="?module=<?php echo $MODULE_ID; ?>&action=check_feedback_webhook" class="btn ga-btn">🔎 Проверить webhook</a>
                                            <a href="?module=<?php echo $MODULE_ID; ?>&action=clear_feedback_stats" class="btn ga-btn">♻ Очистить статистику</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="ga-grid3 ga-mt">
                                    <div><label>Кнопка #1</label><input type="text" name="feedback_positive_text" value="<?php echo h($data['feedback_positive_text']); ?>"></div>
                                    <div><label>Кнопка #2</label><input type="text" name="feedback_negative_text" value="<?php echo h($data['feedback_negative_text']); ?>"></div>
                                    <div><label>Кнопка #3 (опц.)</label><input type="text" name="feedback_neutral_text" value="<?php echo h($data['feedback_neutral_text']); ?>"></div>
                                </div>

                                <div class="ga-grid2 ga-mt-sm">
                                    <div><label>🧠 Почему</label><input type="text" name="feedback_reasons_text" value="<?php echo h($data['feedback_reasons_text']); ?>"></div>
                                    <div><label>⚠️ Риски</label><input type="text" name="feedback_risks_text" value="<?php echo h($data['feedback_risks_text']); ?>"></div>
                                    <div><label>📊 Статистика</label><input type="text" name="feedback_stats_text" value="<?php echo h($data['feedback_stats_text']); ?>"></div>
                                    <div><label>❓ Помощь</label><input type="text" name="feedback_help_text" value="<?php echo h($data['feedback_help_text']); ?>"></div>
                                </div>

                                <div class="ga-grid2 ga-mt-sm">
                                    <div><label>Support-кнопка</label><input type="text" name="feedback_support_text" value="<?php echo h($data['feedback_support_text']); ?>"></div>
                                    <div><label>Support URL</label><input type="text" name="feedback_support_url" value="<?php echo h($data['feedback_support_url']); ?>" placeholder="https://t.me/..."></div>
                                </div>

                                <div class="ga-grid2 ga-mt-sm">
                                    <div><label>Ответ после клика</label><input type="text" name="feedback_thanks_text" value="<?php echo h($data['feedback_thanks_text']); ?>"></div>
                                    <div class="ga-box ga-box-compact">
                                        <div class="ga-box-title">Webhook URL</div>
                                        <div class="ga-code">
                                            <code id="ga_feedback_url"><?php echo h($FEEDBACK_WEBHOOK_URL); ?></code>
                                            <button type="button" class="btn ga-btn ga-copy" data-ga-copy="#ga_feedback_url">Скопировать</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="ga-grid2 ga-mt">
                                    <div class="ga-box">
                                        <div class="ga-box-title">📦 Экспорт для “Tunnel”</div>
                                        <div class="ga-box-text ga-dim">Вставь это в модуле <b>tunnel</b> → импорт.</div>
                                        <textarea id="ga_tunnel_export" readonly rows="3" class="ga-ta-mono"><?php echo h($GA_TUNNEL_EXPORT); ?></textarea>
                                        <div class="ga-btnrow ga-mt-sm">
                                            <button type="button" class="btn ga-btn ga-copy" data-ga-copy="#ga_tunnel_export">Скопировать экспорт</button>
                                        </div>
                                    </div>
                                    <div class="ga-box">
                                        <div class="ga-box-title">Token / лимиты</div>
                                        <label class="ga-inline ga-warn"><input type="checkbox" name="regenerate_feedback_token" value="1">Сгенерировать новый feedback_token при сохранении</label>
                                        <div class="ga-grid2 ga-mt-sm">
                                            <div><label>Trace-записей</label><input type="number" min="50" max="5000" name="feedback_store_max_traces" value="<?php echo h($data['feedback_store_max_traces']); ?>"></div>
                                            <div><label>Пользователей/trace</label><input type="number" min="100" max="20000" name="feedback_store_max_users" value="<?php echo h($data['feedback_store_max_users']); ?>"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="ga-details ga-mt" open>
                        <summary class="ga-summary">🧠 Gemini AI</summary>
                        <div class="card ga-card">
                            <div class="card-body ga-body">
                                <div class="ga-grid2">
                                    <div>
                                        <label>Gemini API key</label>
                                        <input type="password" name="gemini_key" value="<?php echo h($data['gemini_key']); ?>" placeholder="AIzaSy...">
                                        <div class="ga-hint">Получить: <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a></div>
                                    </div>
                                    <div>
                                        <label>Модель</label>
                                        <select name="gemini_model" class="box-input">
                                            <optgroup label="Актуальные модели">
                                                <option value="gemini-2.5-flash" <?php echo ($data['gemini_model'] === 'gemini-2.5-flash') ? 'selected' : ''; ?>>gemini-2.5-flash (recommended)</option>
                                                <option value="gemini-2.5-flash-lite" <?php echo ($data['gemini_model'] === 'gemini-2.5-flash-lite') ? 'selected' : ''; ?>>gemini-2.5-flash-lite</option>
                                                <option value="gemini-flash-latest" <?php echo ($data['gemini_model'] === 'gemini-flash-latest') ? 'selected' : ''; ?>>gemini-flash-latest</option>
                                                <option value="gemini-3-flash-preview" <?php echo ($data['gemini_model'] === 'gemini-3-flash-preview') ? 'selected' : ''; ?>>gemini-3-flash-preview</option>
                                            </optgroup>
                                            <optgroup label="Legacy">
                                                <option value="gemini-2.0-flash" <?php echo ($data['gemini_model'] === 'gemini-2.0-flash') ? 'selected' : ''; ?>>gemini-2.0-flash</option>
                                                <option value="gemini-2.0-flash-001" <?php echo ($data['gemini_model'] === 'gemini-2.0-flash-001') ? 'selected' : ''; ?>>gemini-2.0-flash-001</option>
                                                <option value="gemini-1.5-pro" <?php echo ($data['gemini_model'] === 'gemini-1.5-pro') ? 'selected' : ''; ?>>gemini-1.5-pro</option>
                                                <option value="gemini-1.5-flash" <?php echo ($data['gemini_model'] === 'gemini-1.5-flash') ? 'selected' : ''; ?>>gemini-1.5-flash</option>
                                                <option value="gemini-1.5-flash-8b" <?php echo ($data['gemini_model'] === 'gemini-1.5-flash-8b') ? 'selected' : ''; ?>>gemini-1.5-flash-8b</option>
                                            </optgroup>
                                        </select>
                                        <label class="ga-inline ga-mt-sm"><input type="checkbox" name="gemini_discover_models" <?php echo ($data['gemini_discover_models'] == '1') ? 'checked' : ''; ?>>Автоопределение моделей (ListModels)</label>
                                    </div>
                                </div>

                                <div class="ga-grid2 ga-mt-sm">
                                    <div><label>API версии (csv)</label><input type="text" name="gemini_api_versions" value="<?php echo h($data['gemini_api_versions']); ?>" placeholder="v1beta,v1"></div>
                                    <div><label>Fallback модели (csv)</label><textarea name="gemini_fallback_models" rows="2" class="ga-ta"><?php echo h($data['gemini_fallback_models']); ?></textarea></div>
                                </div>

                                <div class="ga-help ga-mt-sm">
                                    Если видишь <code>FAILED_PRECONDITION: User location is not supported</code> — включай Bridge (прокси) ниже.
                                </div>

                                <div class="ga-grid2 ga-mt">
                                    <div class="ga-box">
                                        <div class="ga-box-title">🌍 AI Proxy Bridge</div>
                                        <label class="ga-inline"><input type="checkbox" name="use_bridge" value="1" <?php echo ($data['use_bridge'] == '1') ? 'checked' : ''; ?>>Использовать внешний прокси-сервер</label>
                                        <label class="ga-mt-sm">Bridge URL</label>
                                        <input type="text" name="bridge_url" value="<?php echo h($data['bridge_url']); ?>" placeholder="https://remote-site.com/admin.php?module=ai-bridge&action=api_proxy">
                                        <label class="ga-mt-sm">Bridge secret</label>
                                        <input type="text" name="bridge_secret" value="<?php echo h($data['bridge_secret']); ?>" placeholder="Секрет из Bridge">
                                        <div class="ga-hint ga-mt-sm">IP этого сервера: <b><?php echo $_SERVER['SERVER_ADDR'] ?? 'не определен'; ?></b></div>
                                    </div>
                                    <div class="ga-box">
                                        <div class="ga-box-title">⏱ Таймауты / SSL</div>
                                        <div class="ga-grid2">
                                            <div><label>Connect timeout</label><input type="number" min="3" max="60" name="curl_connect_timeout" value="<?php echo h($data['curl_connect_timeout']); ?>"></div>
                                            <div><label>Price API timeout</label><input type="number" min="5" max="120" name="gold_timeout" value="<?php echo h($data['gold_timeout']); ?>"></div>
                                            <div><label>AI timeout</label><input type="number" min="5" max="180" name="ai_timeout" value="<?php echo h($data['ai_timeout']); ?>"></div>
                                            <div><label>Telegram timeout</label><input type="number" min="5" max="120" name="tg_timeout" value="<?php echo h($data['tg_timeout']); ?>"></div>
                                        </div>
                                        <div class="ga-grid2 ga-mt-sm">
                                            <div><label>ListModels timeout</label><input type="number" min="5" max="90" name="gemini_discovery_timeout" value="<?php echo h($data['gemini_discovery_timeout']); ?>"></div>
                                            <div class="ga-flex-end"><label class="ga-inline"><input type="checkbox" name="curl_ssl_verify" <?php echo ($data['curl_ssl_verify'] == '1') ? 'checked' : ''; ?>>Проверять SSL</label></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="ga-details ga-mt">
                        <summary class="ga-summary">🧾 Логирование</summary>
                        <div class="card ga-card">
                            <div class="card-body ga-body">
                                <div class="ga-grid2">
                                    <div>
                                        <label class="ga-inline"><input type="checkbox" name="logging_enabled" <?php echo ($data['logging_enabled'] == '1') ? 'checked' : ''; ?>>Включить журналирование</label>
                                        <label class="ga-inline"><input type="checkbox" name="log_success_requests" <?php echo ($data['log_success_requests'] == '1') ? 'checked' : ''; ?>>Логировать успехи</label>
                                        <label class="ga-inline"><input type="checkbox" name="log_include_http_body" <?php echo ($data['log_include_http_body'] == '1') ? 'checked' : ''; ?>>Включать raw HTTP body</label>
                                    </div>
                                    <div class="ga-grid2">
                                        <div>
                                            <label>Уровень логов</label>
                                            <select name="log_level" class="box-input">
                                                <option value="error" <?php echo ($data['log_level'] === 'error') ? 'selected' : ''; ?>>error</option>
                                                <option value="warning" <?php echo ($data['log_level'] === 'warning') ? 'selected' : ''; ?>>warning</option>
                                                <option value="info" <?php echo ($data['log_level'] === 'info') ? 'selected' : ''; ?>>info</option>
                                                <option value="debug" <?php echo ($data['log_level'] === 'debug') ? 'selected' : ''; ?>>debug</option>
                                                <option value="trace" <?php echo ($data['log_level'] === 'trace') ? 'selected' : ''; ?>>trace</option>
                                            </select>
                                        </div>
                                        <div><label>Максимум записей</label><input type="number" min="50" max="5000" name="log_max_entries" value="<?php echo h($data['log_max_entries']); ?>"></div>
                                        <div><label>Лимит сообщения</label><input type="number" min="200" max="20000" name="log_message_limit" value="<?php echo h($data['log_message_limit']); ?>"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="ga-details ga-mt" open>
                        <summary class="ga-summary">🧩 Prompt / Threshold</summary>
                        <div class="card ga-card">
                            <div class="card-body ga-body">
                                <div class="ga-grid2">
                                    <div>
                                        <label>Threshold (0..100)</label>
                                        <input type="number" name="threshold" value="<?php echo h($data['threshold']); ?>" min="0" max="100">
                                        <div class="ga-hint">В рабочем режиме отправка в Telegram только если <code>importance ≥ threshold</code>.</div>
                                    </div>
                                    <div class="ga-help">
                                        Промпт должен требовать строгий JSON и содержать <code>telegram_message</code>.
                                    </div>
                                </div>
                                <label class="ga-mt-sm">Системный промпт</label>
                                <textarea name="prompt" rows="8" class="ga-ta"><?php echo h($data['prompt']); ?></textarea>
                            </div>
                        </div>
                    </details>

                    <details class="ga-details ga-mt">
                        <summary class="ga-summary">💅 Виджет на сайте (CSS/JS)</summary>
                        <div class="card ga-card">
                            <div class="card-body ga-body">
                                <label>CSS</label>
                                <textarea name="module_css" rows="5" class="ga-ta-mono"><?php echo h($data['module_css']); ?></textarea>
                                <label class="ga-mt-sm">JS</label>
                                <textarea name="module_js" rows="2" class="ga-ta-mono"><?php echo h($data['module_js']); ?></textarea>
                            </div>
                        </div>
                    </details>

                    <div class="ga-savebar">
                        <button class="btn btn-primary ga-btn-strong" type="submit">💾 Сохранить настройки</button>
                    </div>
                </form>
            </div>

            <div class="ga-side">
                <div class="card ga-card ga-mt">
                    <div class="card-head ga-head">📡 Снапшоты</div>
                    <div class="card-body ga-body">
                        <details class="ga-details" open>
                            <summary class="ga-summary">CommodityPriceAPI response</summary>
                            <div class="ga-pre"><pre><?php echo h(json_encode($data['last_raw_gold'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
                        </details>
                        <details class="ga-details">
                            <summary class="ga-summary">Gemini AI parsed JSON</summary>
                            <div class="ga-pre"><pre><?php echo h(json_encode($data['last_raw_ai'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
                        </details>
                    </div>
                </div>

                <div class="card ga-card ga-mt">
                    <div class="card-head ga-head">📋 Логи</div>
                    <div class="card-body ga-body">
                        <div class="ga-grid2 ga-mb-sm">
                            <input id="ga_log_filter" type="text" placeholder="Фильтр (run id / текст / статус)..." class="ga-input">
                            <div class="ga-flex-end">
                                <a href="?module=<?php echo $MODULE_ID; ?>&action=clear_logs" class="btn ga-btn">🗑 Очистить</a>
                            </div>
                        </div>
                    </div>
                    <div class="ga-logtable">
                        <table class="ga-table">
                            <thead>
                                <tr>
                                    <th>Время</th>
                                    <th>Run ID</th>
                                    <th>Система</th>
                                    <th>Level</th>
                                    <th>Статус</th>
                                    <th>Сообщение</th>
                                </tr>
                            </thead>
                            <tbody id="ga_log_rows">
                                <?php if (empty($modlogs)): ?>
                                    <tr><td colspan="6" class="ga-center ga-dim">Нет записей. Запусти <b>Тестовый RUN</b>.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($modlogs as $l): ?>
                                    <?php
                                    $msgLimit = max(120, min(1000, (int) ($data['log_message_limit'] ?? 300)));
                                    $previewLimit = min(260, $msgLimit);
                                    $msg = (string) ($l['msg'] ?? '');
                                    $metaStr = '';
                                    if (!empty($l['meta']) && is_array($l['meta'])) {
                                        $metaStr = ' | meta=' . json_encode($l['meta'], JSON_UNESCAPED_UNICODE);
                                    }
                                    $combined = $msg . $metaStr;
                                    $combinedPreview = ga_mb_substr($combined, 0, $previewLimit) . ((ga_mb_strlen($combined) > $previewLimit) ? '...' : '');
                                    $status = (string) ($l['status'] ?? '');
                                    $chipClass = ($status === 'error') ? 'bad' : (($status === 'success') ? 'ok' : 'warn');
                                    ?>
                                    <tr data-ga-log="<?php echo h(strtolower((string) ($l['time'] ?? '') . ' ' . ($l['run_id'] ?? '') . ' ' . ($l['type'] ?? '') . ' ' . ($l['level'] ?? '') . ' ' . ($l['status'] ?? '') . ' ' . $combined)); ?>">
                                        <td class="ga-dim"><?php echo h($l['time'] ?? '--'); ?></td>
                                        <td class="ga-mono"><?php echo h($l['run_id'] ?? '--'); ?></td>
                                        <td><b><?php echo h($l['type'] ?? '--'); ?></b></td>
                                        <td class="ga-mono ga-dim"><?php echo h(strtoupper($l['level'] ?? 'INFO')); ?></td>
                                        <td><span class="ga-chip <?php echo $chipClass; ?>"><?php echo h(strtoupper($status ?: '--')); ?></span></td>
                                        <td class="ga-mono ga-wrap"><?php echo h($combinedPreview); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            function gaGetText(selector) {
                var el = document.querySelector(selector);
                if (!el) return '';
                if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') return (el.value || '').trim();
                return (el.textContent || '').trim();
            }

            function gaCopy(text) {
                if (!text) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).catch(function () {});
                    return;
                }
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', 'readonly');
                ta.style.position = 'absolute';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
            }

            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-ga-copy]') : null;
                if (!btn) return;
                var sel = btn.getAttribute('data-ga-copy');
                gaCopy(gaGetText(sel));
                btn.classList.add('is-copied');
                setTimeout(function(){ btn.classList.remove('is-copied'); }, 900);
            });

            var filter = document.getElementById('ga_log_filter');
            var rows = document.querySelectorAll('#ga_log_rows tr[data-ga-log]');
            if (filter && rows && rows.length) {
                filter.addEventListener('input', function () {
                    var q = (filter.value || '').toLowerCase().trim();
                    rows.forEach(function (tr) {
                        var hay = (tr.getAttribute('data-ga-log') || '');
                        tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
                    });
                });
            }
        })();
    </script>

    <style>
        /* Scoped UI */
        .ga-admin { --ga-bg: rgba(255,255,255,0.02); --ga-border: rgba(148,163,184,0.22); --ga-text-dim: rgba(226,232,240,0.7); --ga-gold: #d4af37; --ga-blue: #38bdf8; --ga-red: #f48771; --ga-green: #89d185; }
        .ga-title { font-size: 18px; font-weight: 900; letter-spacing: .2px; }
        .ga-chips { margin-top: 8px; display:flex; flex-wrap: wrap; gap: 8px; }
        .ga-actions { display:flex; gap: 8px; flex-wrap: wrap; align-items:center; justify-content:flex-end; }
        .ga-topbar { display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; flex-wrap: wrap; }
        .ga-btn { background:#1f2937; color:#fff; border: 1px solid rgba(148,163,184,0.25); }
        .ga-btn:hover { filter: brightness(1.08); }
        .ga-btn-strong { background: var(--ga-gold) !important; color:#000 !important; border-color: rgba(0,0,0,0.2) !important; font-weight: 900; }
        .ga-btn-ghost { opacity: .85; }
        .ga-chip { display:inline-flex; align-items:center; gap:6px; padding: 4px 8px; border-radius: 999px; border:1px solid var(--ga-border); font-size: 12px; color:#e2e8f0; background: rgba(2,6,23,0.35); }
        .ga-chip.ok { border-color: rgba(137,209,133,0.55); color: #c7f7c4; }
        .ga-chip.warn { border-color: rgba(244,192,36,0.55); color: #fde68a; }
        .ga-chip.bad { border-color: rgba(244,135,113,0.6); color: #fecaca; }
        .ga-chip.muted { opacity: 0.7; }

        .ga-card { background: var(--ga-bg); border: 1px solid var(--ga-border); border-radius: 10px; overflow: hidden; }
        .ga-head { border-left: 3px solid var(--ga-gold); padding-left: 12px !important; background: linear-gradient(90deg, rgba(212, 175, 55, 0.12) 0%, rgba(212, 175, 55, 0) 100%); font-weight: 900; }
        .ga-body { background: rgba(2,6,23,0.15); }

        .ga-layout { display:flex; gap: 14px; flex-wrap: wrap; margin-top: 14px; }
        .ga-side { flex: 1; min-width: 420px; }
        .ga-form { flex: 2; min-width: 320px; }
        @media (max-width: 980px) { .ga-side { min-width: 320px; } }

        .ga-grid2 { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .ga-grid3 { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        @media (max-width: 980px) { .ga-grid2, .ga-grid3 { grid-template-columns: 1fr; } }

        .ga-box { border: 1px dashed rgba(148,163,184,0.3); border-radius: 10px; padding: 12px; background: rgba(2,6,23,0.18); }
        .ga-box-compact { padding: 10px; }
        .ga-box-title { font-weight: 900; margin-bottom: 6px; color: #e2e8f0; }
        .ga-box-text { font-size: 12px; line-height: 1.5; color: var(--ga-text-dim); }
        .ga-dim { color: var(--ga-text-dim); }
        .ga-ok { color: var(--ga-green); }
        .ga-warn { color: #fbbf24; }
        .ga-mono { font-family: var(--font-code, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace); }
        .ga-wrap { word-break: break-word; }
        .ga-center { text-align:center; }

        .ga-hint { margin-top: 4px; font-size: 11px; color: rgba(226,232,240,0.6); }
        .ga-help { padding: 10px 12px; border-radius: 10px; background: rgba(14,165,233,0.07); border: 1px dashed rgba(14,165,233,0.35); color: rgba(226,232,240,0.9); font-size: 12px; line-height: 1.5; }
        .ga-toggle { display:flex; align-items:center; justify-content:space-between; gap: 10px; padding: 10px 12px; border: 1px solid rgba(148,163,184,0.18); border-radius: 10px; background: rgba(2,6,23,0.22); font-weight: 900; }
        .ga-inline { display:flex; gap:8px; align-items:center; margin-top: 8px; font-weight: 700; color: rgba(226,232,240,0.9); }
        .ga-inline input[type="checkbox"] { width:auto; margin:0; transform: translateY(1px); }

        .ga-summary { cursor:pointer; padding: 10px 2px; font-weight: 900; color: rgba(212,175,55,0.95); }
        .ga-summary::-webkit-details-marker { display:none; }

        .ga-ta { width: 100%; }
        .ga-ta-mono { width: 100%; font-family: var(--font-code, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace); font-size: 12px; background: #0b1220; border: 1px solid rgba(148,163,184,0.25); color: #e2e8f0; border-radius: 10px; padding: 10px; }
        .ga-input { width: 100%; border-radius: 10px; }
        .ga-inlinecode { display:inline-block; font-family: var(--font-code, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace); background: rgba(2,6,23,0.35); border: 1px solid rgba(148,163,184,0.22); padding: 6px 8px; border-radius: 10px; color: #e2e8f0; }
        .ga-code { display:flex; gap: 8px; align-items:center; margin-top: 8px; flex-wrap: wrap; }
        .ga-code code { display:block; flex:1; min-width: 260px; background:#0b1220; border:1px solid rgba(148,163,184,0.25); border-radius: 10px; padding: 10px; color:#e2e8f0; font-family: var(--font-code, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace); font-size: 12px; word-break: break-all; }
        .ga-copy.is-copied { border-color: rgba(137,209,133,0.8) !important; box-shadow: 0 0 0 3px rgba(137,209,133,0.18); }

        .ga-btnrow { display:flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .ga-flex-end { display:flex; justify-content:flex-end; align-items:center; }
        .ga-savebar { position: sticky; bottom: 8px; z-index: 5; display:flex; justify-content:flex-end; padding: 10px 0; }

        .ga-pre { background: #0b1220; border: 1px solid rgba(148,163,184,0.25); border-radius: 10px; padding: 10px; overflow:auto; max-height: 220px; }
        .ga-pre pre { margin:0; font-size: 11px; color:#cbd5e1; }

        .ga-logtable { padding: 0; overflow:auto; max-height: 620px; border-top: 1px solid rgba(148,163,184,0.12); }
        .ga-table { width: 100%; font-size: 12px; border-collapse: collapse; }
        .ga-table thead th { position: sticky; top: 0; background: rgba(2,6,23,0.9); border-bottom: 1px solid rgba(148,163,184,0.18); padding: 10px 10px; text-align:left; }
        .ga-table td { border-bottom: 1px solid rgba(148,163,184,0.10); padding: 10px 10px; vertical-align: top; }
        .ga-table tr:hover td { background: rgba(148,163,184,0.06); }

        .ga-mt { margin-top: 12px; }
        .ga-mt-sm { margin-top: 8px; }
        .ga-mb-sm { margin-bottom: 10px; }
        .ga-ml { margin-left: 8px; }
        a[target="_blank"] { color: rgba(56,189,248,0.95); }
        a[target="_blank"]:hover { color: #7dd3fc; }
    </style>
<?php else: ?>

<!-- LEGACY UI (append ?legacy_ui=1 to show) -->
<div class="panel-header" style="flex-wrap: wrap;">
    <h1>Управление XAU Мониторингом 🪙</h1>
    <div class="panel-actions" style="display:flex; gap: 10px;">
        <span class="status-badge" style="border:1px solid #d4af37; color:#d4af37;">CRON Target:
            /admin.php?module=gold-analyzer</span>
        <?php if (($data['bot_active'] ?? '0') !== '1'): ?>
            <a href="?module=<?php echo $MODULE_ID; ?>&action=start_now" class="btn btn-primary"
                style="background:#d4af37;color:#000;">▶ ЗАПУСТИТЬ СЕЙЧАС</a>
        <?php else: ?>
            <a href="?module=<?php echo $MODULE_ID; ?>&action=force_run" class="btn btn-primary"
                style="background:#d4af37;color:#000;">⚡ RUN СЕЙЧАС</a>
        <?php endif; ?>
        <a href="?module=<?php echo $MODULE_ID; ?>&action=test_run" class="btn"
            style="background:#0ea5e9;color:#001018;">🧪 ТЕСТОВЫЙ RUN</a>
        <a href="?module=<?php echo $MODULE_ID; ?>&action=clear_logs" class="btn"
            style="background:#30363d;color:#fff;">🗑 Очистить логи</a>
        <a href="?module=<?php echo $MODULE_ID; ?>&action=clear_feedback_stats" class="btn"
            style="background:#3b2f2f;color:#fff;">♻ Очистить feedback-статистику</a>
    </div>
</div>

<div class="card" style="background: rgba(212, 175, 55, 0.05); border: 1px dashed #d4af37;">
    <div class="card-head">CRON Расписание Автоматизации (Linux Server/cPanel)</div>
    <div class="card-body">
        <p style="color:var(--text-dim); margin-top:0;">
            <?php echo ($data['test_mode'] === '1') ? '<b style="color:#f48771">ТЕСТОВЫЙ РЕЖИМ:</b> Выставьте интервал "Раз в минуту" в вашей панели.' : '<b>РАБОЧИЙ РЕЖИМ:</b> Выставьте интервал "Раз в 12 часов" в вашей панели.'; ?>
            <br>Вставьте команду ниже в поле "Command" или "Команда":
        </p>
        <code
            style="color: #89d185; display:block; padding:10px; background:#181818; word-break:break-all; user-select:all;">
           curl -L "<?php echo $CRON_URL; ?>"
        </code>
    </div>
</div>

<div class="card" style="background: rgba(56, 189, 248, 0.05); border: 1px dashed #0ea5e9; margin-top: 20px;">
    <div class="card-head" style="border-left-color: #0ea5e9;">⚙️ Очередь обратной связи (Feedback Worker)</div>
    <div class="card-body">
        <p style="color:var(--text-dim); margin-top:0;">Для мгновенного отклика кнопок настройте второй Cron (раз в 1 минуту):</p>
        <code style="color: #38bdf8; display:block; padding:10px; background:#181818; word-break:break-all; user-select:all;">
           curl -L "<?php echo ga_build_module_url($MODULE_ID, ['action' => 'run_worker', 'cron_token' => $data['cron_token']]); ?>"
        </code>
        <p style="color:#aaa; margin-top:10px">
            <b>Важно (Очередь):</b> На shared-хостинге настройте cron-задачу для обработки очереди обратной связи (раз в 1 минуту):<br>
            <code style="background:#111; padding:5px; color:#89d185;">*/1 * * * * curl -L "<?php echo ga_build_module_url($MODULE_ID, ['action' => 'run_worker', 'cron_token' => $data['cron_token']]); ?>" > /dev/null 2>&1</code>
        </p>
    </div>
</div>

<div class="row" style="flex-wrap:wrap">

    <div class="col" style="flex:1; min-width: 300px;">
        <form method="POST" class="app-form">
            <input type="hidden" name="action" value="save_configs">
            <div class="card">
                <div class="card-head">🔑 Основные настройки и доступ</div>
                <div class="card-body"
                    style="background: rgba(255,255,255,0.03); margin-bottom:15px; border-radius:8px; padding:15px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div style="background:#000; padding:12px; border-radius:10px; border:1px solid #333; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:700; color:<?php echo ($data['bot_active'] == '1') ? '#89d185' : '#f48771'; ?>;">
                                <?php echo ($data['bot_active'] == '1') ? '🚦 СИСТЕМА ON' : '🚦 СИСТЕМА OFF'; ?>
                            </span>
                            <span class="mod-switch">
                                <input type="checkbox" name="bot_active" value="1" <?php echo ($data['bot_active'] == '1') ? 'checked' : ''; ?>>
                                <span class="mod-switch-slider"></span>
                            </span>
                        </div>
                        <div style="background:#000; padding:12px; border-radius:10px; border:1px solid #333; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:700; color:#f48771;">🧪 ТЕСТОВЫЙ РЕЖИМ</span>
                            <span class="mod-switch">
                                <input type="checkbox" name="test_mode" value="1" <?php echo ($data['test_mode'] == '1') ? 'checked' : ''; ?>>
                                <span class="mod-switch-slider"></span>
                            </span>
                        </div>
                    </div>
                    <div style="background:#000; padding:12px; border-radius:10px; border:1px solid #333; display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                        <span style="font-weight:700; color:#fff;">✨ ИСПОЛЬЗОВАТЬ GEMINI AI</span>
                        <span class="mod-switch">
                            <input type="checkbox" name="use_ai" value="1" <?php echo ($data['use_ai'] == '1') ? 'checked' : ''; ?>>
                            <span class="mod-switch-slider"></span>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <label>CommodityPriceAPI ключ доступа:</label>
                    <input type="password" name="goldapi_key" value="<?php echo h($data['goldapi_key']); ?>"
                        placeholder="e16ce0ea-c213-...">

                    <label>Telegram БОТ Токен:</label>
                    <input type="password" name="tg_token" value="<?php echo h($data['tg_token']); ?>">

                    <label>ID Канала или Группы (начинается на -100):</label>
                    <input type="text" name="tg_chat_id" value="<?php echo h($data['tg_chat_id']); ?>">
                    <a href="?module=<?php echo $MODULE_ID; ?>&action=test_tg" class="btn"
                        style="background:#0088cc; color:#fff; display:inline-block; margin-top:5px; padding: 5px 15px; font-size:12px; border-radius:4px; text-decoration:none;">🧪
                        Проверить TG (Test)</a>
                    <div style="margin-top:12px; padding:10px; background:#111; border:1px solid #2f2f2f; border-radius:6px;">
                        <div style="font-weight:700; color:#d4af37; margin-bottom:8px;">Feedback кнопки Telegram</div>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff;">
                            <input type="checkbox" name="feedback_buttons_enabled" <?php echo ($data['feedback_buttons_enabled'] == '1') ? 'checked' : ''; ?>
                                style="width:auto;margin:0;">
                            Включить inline-кнопки обратной связи под постом
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:6px;">
                            <input type="checkbox" name="feedback_include_test" <?php echo ($data['feedback_include_test'] == '1') ? 'checked' : ''; ?>
                                style="width:auto;margin:0;">
                            Показывать кнопки и в тестовом режиме
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:6px;">
                            <input type="checkbox" name="feedback_auto_webhook" <?php echo ($data['feedback_auto_webhook'] == '1') ? 'checked' : ''; ?>
                                style="width:auto;margin:0;">
                            Автонастройка webhook перед отправкой (рекомендуется)
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:6px;">
                            <input type="checkbox" name="feedback_show_counters" <?php echo ($data['feedback_show_counters'] == '1') ? 'checked' : ''; ?>
                                style="width:auto;margin:0;">
                            Показывать счетчики на кнопках (👍 12, 👎 3, и т.д.)
                        </label>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:6px;">
                            <input type="checkbox" name="feedback_force_https" <?php echo ($data['feedback_force_https'] == '1') ? 'checked' : ''; ?>
                                style="width:auto;margin:0;">
                            Принудительно использовать HTTPS для webhook URL
                        </label>
                        <div style="margin-top:8px;">
                            <small style="display:block;color:#aaa;">Webhook Base URL (если прокси/Cloudflare ломает авто-URL)</small>
                            <input type="text" name="feedback_webhook_base_url"
                                value="<?php echo h($data['feedback_webhook_base_url']); ?>" placeholder="https://example.com/admin.php">
                        </div>
                        <a href="?module=<?php echo $MODULE_ID; ?>&action=setup_feedback_webhook" class="btn"
                            style="background:#334155; color:#fff; display:inline-block; margin-top:8px; padding: 5px 12px; font-size:12px; border-radius:4px; text-decoration:none;">⚙️
                            Переустановить Feedback Webhook</a>
                        <a href="?module=<?php echo $MODULE_ID; ?>&action=check_feedback_webhook" class="btn"
                            style="background:#1f2937; color:#fff; display:inline-block; margin-top:8px; margin-left:6px; padding: 5px 12px; font-size:12px; border-radius:4px; text-decoration:none;">🔎
                            Проверить Feedback Webhook</a>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px;">
                            <div>
                                <small style="display:block;color:#aaa;">Текст кнопки #1</small>
                                <input type="text" name="feedback_positive_text"
                                    value="<?php echo h($data['feedback_positive_text']); ?>" placeholder="👍 Полезно">
                            </div>
                            <div>
                                <small style="display:block;color:#aaa;">Текст кнопки #2</small>
                                <input type="text" name="feedback_negative_text"
                                    value="<?php echo h($data['feedback_negative_text']); ?>" placeholder="👎 Мимо">
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <small style="display:block;color:#aaa;">Текст кнопки #3 (опционально)</small>
                            <input type="text" name="feedback_neutral_text"
                                value="<?php echo h($data['feedback_neutral_text']); ?>" placeholder="🤔 Нужны детали">
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px;">
                            <div>
                                <small style="display:block;color:#aaa;">Кнопка "Почему"</small>
                                <input type="text" name="feedback_reasons_text"
                                    value="<?php echo h($data['feedback_reasons_text']); ?>" placeholder="🧠 Почему">
                            </div>
                            <div>
                                <small style="display:block;color:#aaa;">Кнопка "Риски"</small>
                                <input type="text" name="feedback_risks_text"
                                    value="<?php echo h($data['feedback_risks_text']); ?>" placeholder="⚠️ Риски">
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px;">
                            <div>
                                <small style="display:block;color:#aaa;">Кнопка "Статистика"</small>
                                <input type="text" name="feedback_stats_text"
                                    value="<?php echo h($data['feedback_stats_text']); ?>" placeholder="📊 Статистика">
                            </div>
                            <div>
                                <small style="display:block;color:#aaa;">Кнопка "Помощь"</small>
                                <input type="text" name="feedback_help_text"
                                    value="<?php echo h($data['feedback_help_text']); ?>" placeholder="❓ Помощь">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:8px; margin-top:8px;">
                            <div>
                                <small style="display:block;color:#aaa;">Текст support-кнопки</small>
                                <input type="text" name="feedback_support_text"
                                    value="<?php echo h($data['feedback_support_text']); ?>" placeholder="💬 Связаться">
                            </div>
                            <div>
                                <small style="display:block;color:#aaa;">Support URL (https://...)</small>
                                <input type="text" name="feedback_support_url"
                                    value="<?php echo h($data['feedback_support_url']); ?>" placeholder="https://t.me/your_support_chat">
                            </div>
                        </div>

                        <div style="margin-top:8px;">
                            <small style="display:block;color:#aaa;">Ответ пользователю после клика</small>
                            <input type="text" name="feedback_thanks_text"
                                value="<?php echo h($data['feedback_thanks_text']); ?>" placeholder="Спасибо за обратную связь!">
                        </div>

                        <div style="margin-top:10px; font-size:12px; color:#aaa;">Webhook для обработки кликов callback_query:</div>
                        <code
                            style="color:#89d185; display:block; padding:8px; background:#181818; word-break:break-all; user-select:all;"><?php echo h($FEEDBACK_WEBHOOK_URL); ?></code>
                        <div style="margin-top:6px; font-size:12px; color:#aaa;">Команда установки webhook:</div>
                        <code
                            style="color:#89d185; display:block; padding:8px; background:#181818; word-break:break-all; user-select:all;">curl -X POST "https://api.telegram.org/bot<?php echo h($data['tg_token']); ?>/setWebhook" -d "url=<?php echo h($FEEDBACK_WEBHOOK_URL); ?>"</code>

                        <div style="margin-top:10px; padding:10px; background:rgba(14, 165, 233, 0.06); border:1px dashed rgba(14, 165, 233, 0.6); border-radius:8px;">
                            <div style="font-weight:700; color:#7dd3fc; margin-bottom:8px;">📦 Экспорт для модуля «Туннель»</div>
                            <div style="font-size:12px; color:#94a3b8; line-height:1.45; margin-bottom:8px;">
                                Скопируй строку и вставь её в модуле <b>tunnel</b> (импорт). Внутри есть <b>TG токен</b> и <b>feedback_token</b> — не отправляй это третьим лицам.
                            </div>
                            <textarea id="ga_tunnel_export" readonly rows="3" style="width:100%; background:#0b1220; border:1px solid rgba(148,163,184,0.25); color:#e2e8f0; border-radius:8px; padding:10px; font-family:monospace; font-size:12px; user-select:all;"><?php echo h($GA_TUNNEL_EXPORT); ?></textarea>
                            <div style="display:flex; gap:10px; margin-top:8px; flex-wrap:wrap;">
                                <button type="button" class="btn" style="background:#0ea5e9; color:#001018;" onclick="gaCopyTunnelExport()">Скопировать данные</button>
                            </div>
                        </div>
                        <script>
                            function gaCopyTunnelExport() {
                                var el = document.getElementById('ga_tunnel_export');
                                if (!el) return;
                                var value = (el.value || '').trim();
                                if (!value) return;
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(value).catch(function () {
                                        el.focus();
                                        el.select();
                                        try { document.execCommand('copy'); } catch (e) {}
                                    });
                                    return;
                                }
                                el.focus();
                                el.select();
                                try { document.execCommand('copy'); } catch (e) {}
                            }
                        </script>
                        <label style="display:flex; gap:8px; align-items:center; color:#f59e0b; margin-top:8px;">
                            <input type="checkbox" name="regenerate_feedback_token" value="1" style="width:auto;margin:0;">
                            Сгенерировать новый feedback_token при сохранении
                        </label>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px;">
                            <div>
                                <small style="display:block;color:#aaa;">Хранить trace-записей</small>
                                <input type="number" min="50" max="5000" name="feedback_store_max_traces"
                                    value="<?php echo h($data['feedback_store_max_traces']); ?>">
                            </div>
                            <div>
                                <small style="display:block;color:#aaa;">Лимит пользователей/trace</small>
                                <input type="number" min="100" max="20000" name="feedback_store_max_users"
                                    value="<?php echo h($data['feedback_store_max_users']); ?>">
                            </div>
                        </div>
                    </div>

                    <label>Gemini API ключ (взять на <a href="https://aistudio.google.com/app/apikey" target="_blank"
                            style="color:#d4af37">aistudio.google.com</a>):</label>
                    <input type="password" name="gemini_key" value="<?php echo h($data['gemini_key']); ?>"
                        placeholder="AIzaSy...">

                    <label style="margin-top:10px">Модель Gemini:</label>
                    <select name="gemini_model" class="box-input"
                        style="margin-bottom:15px; width: 100%; background: #1e1e1e; border: 1px solid #3e3e42; color: #fff; padding: 8px 10px; border-radius: 3px;">
                        <optgroup label="Актуальные модели">
                            <option value="gemini-2.5-flash" <?php echo ($data['gemini_model'] === 'gemini-2.5-flash') ? 'selected' : ''; ?>>gemini-2.5-flash (recommended)</option>
                            <option value="gemini-2.5-flash-lite" <?php echo ($data['gemini_model'] === 'gemini-2.5-flash-lite') ? 'selected' : ''; ?>>gemini-2.5-flash-lite</option>
                            <option value="gemini-flash-latest" <?php echo ($data['gemini_model'] === 'gemini-flash-latest') ? 'selected' : ''; ?>>gemini-flash-latest</option>
                            <option value="gemini-3-flash-preview" <?php echo ($data['gemini_model'] === 'gemini-3-flash-preview') ? 'selected' : ''; ?>>gemini-3-flash-preview</option>
                        </optgroup>
                        <optgroup label="Legacy (могут быть недоступны)">
                            <option value="gemini-2.0-flash" <?php echo ($data['gemini_model'] === 'gemini-2.0-flash') ? 'selected' : ''; ?>>gemini-2.0-flash</option>
                            <option value="gemini-2.0-flash-001" <?php echo ($data['gemini_model'] === 'gemini-2.0-flash-001') ? 'selected' : ''; ?>>gemini-2.0-flash-001</option>
                            <option value="gemini-1.5-pro" <?php echo ($data['gemini_model'] === 'gemini-1.5-pro') ? 'selected' : ''; ?>>gemini-1.5-pro</option>
                            <option value="gemini-1.5-flash" <?php echo ($data['gemini_model'] === 'gemini-1.5-flash') ? 'selected' : ''; ?>>gemini-1.5-flash</option>
                            <option value="gemini-1.5-flash-8b" <?php echo ($data['gemini_model'] === 'gemini-1.5-flash-8b') ? 'selected' : ''; ?>>gemini-1.5-flash-8b</option>
                        </optgroup>
                    </select>

                    <a href="?module=<?php echo $MODULE_ID; ?>&action=test_ai" class="btn"
                        style="background:#f59e0b; color:#fff; display:inline-block; margin-top:5px; padding: 5px 15px; font-size:12px; border-radius:4px; text-decoration:none;">🧪
                        Проверить AI (Test)</a>
                    <div style="margin-top:8px; font-size:12px; color:#fbbf24; line-height:1.45;">
                        Если в логах видно <code>error_type=location_not_supported</code> или
                        <code>FAILED_PRECONDITION: User location is not supported</code>, это
                        региональное ограничение Gemini API для вашей локации/аккаунта, а не баг PHP-кода.
                    </div>

                    <hr style="margin:14px 0; border-color:#333;">
                    <label>API версии Gemini (через запятую):</label>
                    <input type="text" name="gemini_api_versions" value="<?php echo h($data['gemini_api_versions']); ?>"
                        placeholder="v1beta,v1">

                    <label>Fallback модели Gemini (через запятую):</label>
                    <textarea name="gemini_fallback_models" rows="2"
                        placeholder="gemini-2.5-flash,gemini-flash-latest,gemini-3-flash-preview"><?php echo h($data['gemini_fallback_models']); ?></textarea>

                    <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:8px;">
                        <input type="checkbox" name="gemini_discover_models" <?php echo ($data['gemini_discover_models'] == '1') ? 'checked' : ''; ?>
                            style="width:auto;margin:0;">
                        Автоопределение доступных моделей через ListModels
                    </label>

                    <div style="margin-top:15px; padding:12px; background:rgba(14, 165, 233, 0.05); border:1px solid #0ea5e9; border-radius:8px;">
                        <div style="font-weight:700; color:#0ea5e9; margin-bottom:10px;">🌍 AI Proxy Bridge (Обход локации)</div>
                        <label style="display:flex; gap:10px; align-items:center; color:#fff; cursor:pointer;">
                            <input type="checkbox" name="use_bridge" value="1" <?php echo ($data['use_bridge'] == '1') ? 'checked' : ''; ?> style="width:auto;margin:0;">
                            Использовать внешний прокси-сервер
                        </label>
                        <div style="margin-top:10px;">
                            <small style="display:block;color:#aaa;">URL удаленного модуля (с ?action=api_proxy)</small>
                            <input type="text" name="bridge_url" value="<?php echo h($data['bridge_url']); ?>" placeholder="https://remote-site.com/admin.php?module=ai-bridge&action=api_proxy">
                        </div>
                        <div style="margin-top:8px;">
                            <small style="display:block;color:#aaa;">Секретный ключ моста</small>
                            <input type="text" name="bridge_secret" value="<?php echo h($data['bridge_secret']); ?>" placeholder="Секрет из настроек Bridge">
                        </div>
                        <div style="margin-top:8px; font-size:11px; color:var(--text-dim);">
                            IP этого сервера: <strong style="color:#0ea5e9"><?php echo $_SERVER['SERVER_ADDR'] ?? 'не определен'; ?></strong> (укажите его в разрешенных IP на стороне Bridge).<br>
                            Если включено, запросы будут уходить на указанный URL. API ключ на этом сайте можно будет удалить.
                        </div>
                    </div>

                    <label>Timeout ListModels (сек):</label>
                    <input type="number" min="5" max="90" name="gemini_discovery_timeout"
                        value="<?php echo h($data['gemini_discovery_timeout']); ?>">

                    <label style="margin-top:10px">Timeout/Connect timeout (сек):</label>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:8px;">
                        <div>
                            <small style="display:block;color:#aaa;">Connect timeout</small>
                            <input type="number" min="3" max="60" name="curl_connect_timeout"
                                value="<?php echo h($data['curl_connect_timeout']); ?>">
                        </div>
                        <div>
                            <small style="display:block;color:#aaa;">Gold timeout</small>
                            <input type="number" min="5" max="120" name="gold_timeout"
                                value="<?php echo h($data['gold_timeout']); ?>">
                        </div>
                        <div>
                            <small style="display:block;color:#aaa;">AI timeout</small>
                            <input type="number" min="5" max="180" name="ai_timeout"
                                value="<?php echo h($data['ai_timeout']); ?>">
                        </div>
                        <div>
                            <small style="display:block;color:#aaa;">Telegram timeout</small>
                            <input type="number" min="5" max="120" name="tg_timeout"
                                value="<?php echo h($data['tg_timeout']); ?>">
                        </div>
                    </div>
                    <label style="display:flex; gap:10px; align-items:center; color:#fff; margin-top:8px;">
                        <input type="checkbox" name="curl_ssl_verify" <?php echo ($data['curl_ssl_verify'] == '1') ? 'checked' : ''; ?>
                            style="width:auto;margin:0;">
                        Проверять SSL сертификаты (рекомендуется)
                    </label>
                </div>
            </div>

            <div class="card">
                <div class="card-head">🧾 Расширенное логирование</div>
                <div class="card-body">
                    <label style="display:flex; gap:10px; align-items:center; color:#fff;">
                        <input type="checkbox" name="logging_enabled" <?php echo ($data['logging_enabled'] == '1') ? 'checked' : ''; ?> style="width:auto;margin:0;">
                        Включить журналирование
                    </label>
                    <label style="display:flex; gap:10px; align-items:center; color:#fff;">
                        <input type="checkbox" name="log_success_requests" <?php echo ($data['log_success_requests'] == '1') ? 'checked' : ''; ?> style="width:auto;margin:0;">
                        Логировать успешные события
                    </label>
                    <label style="display:flex; gap:10px; align-items:center; color:#fff;">
                        <input type="checkbox" name="log_include_http_body" <?php echo ($data['log_include_http_body'] == '1') ? 'checked' : ''; ?> style="width:auto;margin:0;">
                        Включать raw HTTP body в логи
                    </label>

                    <label>Уровень логов:</label>
                    <select name="log_level" class="box-input"
                        style="margin-bottom:10px; width:100%; background:#1e1e1e; border:1px solid #3e3e42; color:#fff; padding:8px 10px; border-radius:3px;">
                        <option value="error" <?php echo ($data['log_level'] === 'error') ? 'selected' : ''; ?>>error</option>
                        <option value="warning" <?php echo ($data['log_level'] === 'warning') ? 'selected' : ''; ?>>warning</option>
                        <option value="info" <?php echo ($data['log_level'] === 'info') ? 'selected' : ''; ?>>info</option>
                        <option value="debug" <?php echo ($data['log_level'] === 'debug') ? 'selected' : ''; ?>>debug</option>
                        <option value="trace" <?php echo ($data['log_level'] === 'trace') ? 'selected' : ''; ?>>trace</option>
                    </select>

                    <label>Максимум записей в логе:</label>
                    <input type="number" min="50" max="5000" name="log_max_entries"
                        value="<?php echo h($data['log_max_entries']); ?>">

                    <label>Лимит длины одного сообщения:</label>
                    <input type="number" min="200" max="20000" name="log_message_limit"
                        value="<?php echo h($data['log_message_limit']); ?>">
                </div>
            </div>

            <div class="card">
                <div class="card-head">🧠 Алгоритм и Аналитика (Prompt LLM)</div>
                <div class="card-body">
                    <div class="row">
                        <label style="display:flex; gap:10px; align-items:center; color:white; cursor:pointer;">
                            <input type="checkbox" name="use_ai" <?php echo ($data['use_ai'] == '1') ? 'checked' : ''; ?>
                                style="width: auto; margin: 0; transform: scale(1.4);">
                            ПРОГНАТЬ ДАННЫЕ ЦЕНЫ ЧЕРЕЗ GEMINI
                        </label>
                    </div>

                    <label style="margin-top:15px">Отправка в телеграм, ТОЛЬКО ЕСЛИ Важность по шкале от 0 до 100
                        (которую придумает LLM), превысит или будет равна числу (THRESHOLD):</label>
                    <input type="number" name="threshold" value="<?php echo h($data['threshold']); ?>"
                        style="width: 100px;">

                    <label>ИИ Профессиональный системный промпт (должен ТРЕБОВАТЬ JSON с telegram_message):</label>
                    <textarea name="prompt" rows="8"><?php echo h($data['prompt']); ?></textarea>
                </div>
            </div>

            <details style="background:#1e1e1e">
                <summary style="color:#d4af37">💅 Внешний Вид Front-End Виджета XAU (на вашем сайте)</summary>
                <div>
                    <label>CSS Для HTML Бейджа</label>
                    <textarea name="module_css" class="code-editor"
                        rows="5"><?php echo h($data['module_css']); ?></textarea>

                    <label>Подгрузка Доп JS скриптов (в виджет)</label>
                    <textarea name="module_js" class="code-editor"
                        rows="2"><?php echo h($data['module_js']); ?></textarea>
                </div>
            </details>

            <div style="margin-top:20px;margin-bottom:30px">
                <button class="btn btn-primary" type="submit"
                    style="width:100%; height:45px; background:var(--accent); font-size:16px;">Сохранить Логику
                    Модуля</button>
            </div>
        </form>
    </div>

    <div class="col" style="flex:1; min-width: 450px;">
        <!-- ОКНО API SNAPSHOT -->
        <div class="card" style="height: 100%; display: flex; flex-direction: column;">
            <div class="card-head" style="border-left-color: #89d185;">📡 Данные последнего API запроса</div>
            <div class="card-body" style="display:flex; gap:10px; flex-direction:column;">
                <div style="flex:1;">
                    <div style="font-size:10px; color:#d4af37; font-weight:bold; margin-bottom:5px;">COMMODITYPRICEAPI
                        RESPONSE:</div>
                    <div style="background:#000; padding:10px; border-radius:6px; overflow:auto; max-height:150px;">
                        <pre
                            style="margin:0; font-size:11px; color:#89d185;"><?php echo h(json_encode($data['last_raw_gold'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                </div>
                <div style="flex:1;">
                    <div style="font-size:10px; color:#0ea5e9; font-weight:bold; margin-bottom:5px;">GEMINI AI ANALYSIS
                        RESULT:</div>
                    <div style="background:#000; padding:10px; border-radius:6px; overflow:auto; max-height:150px;">
                        <pre
                            style="margin:0; font-size:11px; color:#0ea5e9;"><?php echo h(json_encode($data['last_raw_ai'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                </div>
            </div>

            <div class="card-head">📋 Операционный Журнал Логирования Системы</div>
            <div class="card-body" style="padding: 0; overflow-y: auto; height: 600px;">
                <table style="width: 100%; font-size:12px;">
                    <thead style="position: sticky; top: 0; background: var(--bg-sidebar);">
                        <tr>
                            <th style="padding-left:15px; width:120px;">Время (Ser)</th>
                            <th style="width:120px">Run ID</th>
                            <th style="width:80px">Система</th>
                            <th style="width:70px">Level</th>
                            <th style="width:70px">Статус</th>
                            <th>Осознание системы</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($modlogs)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:30px;">Система еще не делала фоновых
                                    вызовов через Крон и API Тест кнопки...</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($modlogs as $l): ?>
                            <tr>
                                <td style="color:var(--text-dim);"><?php echo h($l['time'] ?? '--'); ?></td>
                                <td style="color:#9ca3af; font-family: var(--font-code);"><?php echo h($l['run_id'] ?? '--'); ?></td>
                                <td style="font-weight:bold;"><?php echo h($l['type'] ?? '--'); ?></td>
                                <td style="font-family: var(--font-code); color:#9ca3af;"><?php echo h(strtoupper($l['level'] ?? 'INFO')); ?></td>
                                <td><span class="status-badge"
                                        style="<?php echo ($l['status'] == 'error' ? 'color:#f48771; border-color:#f48771' : ($l['status'] == 'success' ? 'color:#89d185; border-color:#89d185' : 'color:#d4af37')); ?>">
                                        <?php echo h(strtoupper($l['status'] ?? '--')); ?> </span></td>
                                <td style="font-family: var(--font-code); color:var(--text-main); word-break: break-word;">
                                    <?php
                                    $msgLimit = max(120, min(1000, (int) ($data['log_message_limit'] ?? 300)));
                                    $previewLimit = min(260, $msgLimit);
                                    $msg = (string) ($l['msg'] ?? '');
                                    $metaStr = '';
                                    if (!empty($l['meta']) && is_array($l['meta'])) {
                                        $metaStr = ' | meta=' . json_encode($l['meta'], JSON_UNESCAPED_UNICODE);
                                    }
                                    $combined = $msg . $metaStr;
                                    echo h(ga_mb_substr($combined, 0, $previewLimit)) . ((ga_mb_strlen($combined) > $previewLimit) ? '...' : '');
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<style>
    /* Module inline-ui touches (Doesn't affect the rest of admin) */
    .card-head {
        border-left: 3px solid #d4af37;
        padding-left: 12px !important;
        background: linear-gradient(90deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0) 100%);
        font-weight: 700;
    }

    table td {
        border-bottom: 1px solid #333 !important;
    }
</style>

<?php endif; ?>
