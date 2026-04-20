<?php
ob_start();
/*
 * ----------------------------------------------------------------
 * PHP SCRIPT WRITER 2: Single-File Admin Panel (Revision 2.0 - UI Overhaul)
 * Style: Desktop App / IDE Like
 * ----------------------------------------------------------------
 */

// (Начало: Определение базовых констант и путей)
@session_start();
@ini_set('display_errors', 0); // В продакшене скрыть
@error_reporting(E_ALL);
@date_default_timezone_set('Europe/Chisinau');

define('ADMIN_VERSION', '2.0');
define('ADMIN_FILE', basename(__FILE__));
define('SITE_ROOT', rtrim(realpath(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR); 
define('ADMIN_DIR', SITE_ROOT . 'admin' . DIRECTORY_SEPARATOR);

define('MODULES_DIR', ADMIN_DIR . 'modules' . DIRECTORY_SEPARATOR);
define('DATA_DIR', ADMIN_DIR . 'data' . DIRECTORY_SEPARATOR);
define('UPLOADS_DIR', DATA_DIR . 'uploads' . DIRECTORY_SEPARATOR);
define('JS_PLUGINS_DIR', ADMIN_DIR . 'js_plugins' . DIRECTORY_SEPARATOR); 

define('PLUGINS_BUNDLE_DIR', ADMIN_DIR . 'plugins_assets' . DIRECTORY_SEPARATOR);
define('PLUGINS_JS_FILE', PLUGINS_BUNDLE_DIR . 'plugins-bundle.js');
define('PLUGINS_CSS_FILE', PLUGINS_BUNDLE_DIR . 'plugins-bundle.css');

define('SETTINGS_FILE', ADMIN_DIR . 'settings.php');
define('GLOBAL_SETTINGS_FILE', ADMIN_DIR . 'global_settings.json');
define('TARGET_INDEX_FILE', SITE_ROOT . 'index.php'); 

// (Начало: Базовые функции)

function get_admin_web_path() {
    $script_filename = $_SERVER['SCRIPT_FILENAME'];
    $document_root = $_SERVER['DOCUMENT_ROOT'];
    if (strpos($script_filename, $document_root) === 0) {
        $relative_path = substr(dirname($script_filename), strlen($document_root));
        return rtrim($relative_path, DIRECTORY_SEPARATOR) . '/admin/plugins_assets/';
    }
    $uri_path = dirname($_SERVER['REQUEST_URI']);
    $admin_pos = strpos($uri_path, '/admin');
    if ($admin_pos !== false) {
        $base_path = substr($uri_path, 0, $admin_pos + 6);
        return rtrim($base_path, '/') . '/plugins_assets/';
    }
    return '/admin/plugins_assets/';
}
$PLUGINS_ASSET_WEB_PATH = get_admin_web_path();

function rrmdir($src) {
    if (!is_dir($src)) return;
    $dir = opendir($src);
    if (!$dir) return;
    while(false !== ( $file = readdir($dir) )) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if (is_dir($full)) rrmdir($full);
            else @unlink($full);
        }
    }
    closedir($dir);
    @rmdir($src);
}

function is_installed() {
    return file_exists(GLOBAL_SETTINGS_FILE);
}

function compile_plugins_bundle($global_config) {
    if (!is_dir(PLUGINS_BUNDLE_DIR)) {
        if (!@mkdir(PLUGINS_BUNDLE_DIR, 0777, true)) return;
         @chmod(PLUGINS_BUNDLE_DIR, 0777);
    }
    
    $js_output = "/* PHP Script Writer 2: JS Plugins Bundle */\n\n";
    $css_output = "/* PHP Script Writer 2: CSS Plugins Bundle */\n\n";
    $js_plugins = $global_config['js_plugins'] ?? [];

    foreach ($js_plugins as $id => $plugin) {
        $file_path = JS_PLUGINS_DIR . $plugin['file'];
        if (file_exists($file_path) && is_readable($file_path)) {
            $content = file_get_contents($file_path);
            if (preg_match('/<style\b[^>]*>(.*?)<\/style>/si', $content, $matches)) {
                 $css_output .= "/* --- Plugin: {$id} -- */\n" . trim($matches[1]) . "\n\n";
                 $content = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $content);
            }
            $js_output .= "// --- Plugin: {$id} ---\n" . $content . "\n\n";
        }
    }

    @file_put_contents(PLUGINS_JS_FILE, $js_output);
    @chmod(PLUGINS_JS_FILE, 0644);
    @file_put_contents(PLUGINS_CSS_FILE, $css_output);
    @chmod(PLUGINS_CSS_FILE, 0644);
}

function setup_environment() {
    $dirs = [ADMIN_DIR, MODULES_DIR, DATA_DIR, UPLOADS_DIR, JS_PLUGINS_DIR, PLUGINS_BUNDLE_DIR]; 
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }
    }

    if (!file_exists(GLOBAL_SETTINGS_FILE)) {
        $default_global_settings = [
            'password' => '8098',
            'admin_title' => 'Admin Panel',
            'accent_color_hex' => '#007acc', // VS Code Blue default
            'js_plugins' => [] 
        ];
        @file_put_contents(GLOBAL_SETTINGS_FILE, json_encode($default_global_settings, JSON_PRETTY_PRINT));
        compile_plugins_bundle($default_global_settings);
    }
    
    if (!file_exists(SETTINGS_FILE)) {
        @file_put_contents(SETTINGS_FILE, "<?php\nreturn [\n];\n");
    }
    
    if (!file_exists(TARGET_INDEX_FILE)) {
        $demo = "<!DOCTYPE html>\n<html>\n<head><meta charset='UTF-8'><title>Site</title></head>\n<body>\n<?php /* BLOCK:welcome START */ ?>\n<h1>Hello World</h1>\n<?php /* BLOCK:welcome END */ ?>\n</body>\n</html>";
        @file_put_contents(TARGET_INDEX_FILE, $demo);
    }

    // Demo module
    $module_id = 'welcome';
    if (!file_exists(MODULES_DIR . $module_id . '.php')) {
        $settings = get_module_settings();
        if (empty($settings[$module_id])) {
            $settings[$module_id] = ['name' => 'Главная (Демо)', 'file' => $module_id . '.php'];
            save_settings_file($settings);
        }
        $json = ['title' => 'Hello World', 'text' => 'Content here', 'module_css' => '', 'module_js' => ''];
        @file_put_contents(DATA_DIR . $module_id . '.json', json_encode($json, JSON_PRETTY_PRINT));

        $code = <<<'EOD'
<?php
if (!defined('IS_ADMIN')) { die('Access Denied'); }
$MODULE_ID = 'welcome';
$TARGET_FILE = SITE_ROOT . 'index.php';
$DATA_FILE = DATA_DIR . $MODULE_ID . '.json';
$START = '<?php /* BLOCK:welcome START */ ?>';
$END = '<?php /* BLOCK:welcome END */ ?>';

$defaults = ['title' => 'Hello', 'text' => 'Text', 'module_css' => '', 'module_js' => ''];
$data = load_module_data($TARGET_FILE, $DATA_FILE, $START, $END, $defaults, function($c){ return []; });

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $new = [
        'title' => $_POST['title'] ?? '',
        'text' => $_POST['text'] ?? '',
        'module_css' => $_POST['module_css'] ?? '',
        'module_js' => $_POST['module_js'] ?? ''
    ];
    file_put_contents($DATA_FILE, json_encode($new, JSON_PRETTY_PRINT));
    
    $html = "\n    <h1>".h($new['title'])."</h1>\n    <p>".h($new['text'])."</p>";
    if($new['module_css']) $html .= "\n    <style>".$new['module_css']."</style>";
    if($new['module_js']) $html .= "\n    <script>".$new['module_js']."</script>";
    
    if (update_target_file($TARGET_FILE, $START, $END, $html)) {
        redirect_with_message('success', 'Сохранено', ['module' => $MODULE_ID]);
    } else {
        redirect_with_message('error', 'Ошибка записи', ['module' => $MODULE_ID]);
    }
}
?>
<div class="panel-header">
    <h3><?php echo h($module_settings[$MODULE_ID]['name']); ?></h3>
    <div class="panel-actions">
         <span class="status-badge">Target: <?php echo basename($TARGET_FILE); ?></span>
    </div>
</div>

<form method="POST" class="app-form">
    <input type="hidden" name="action" value="save">
    <div class="row">
        <div class="col">
            <label>Заголовок</label>
            <input type="text" name="title" value="<?php echo h($data['title']); ?>">
        </div>
    </div>
    <div class="row">
        <div class="col">
            <label>Текст</label>
            <textarea name="text" rows="5"><?php echo h($data['text']); ?></textarea>
        </div>
    </div>
    
    <details>
        <summary>Advanced: CSS / JS</summary>
        <div class="row">
            <div class="col">
                <label>CSS (Scoped)</label>
                <textarea name="module_css" class="code-editor"><?php echo h($data['module_css']); ?></textarea>
            </div>
            <div class="col">
                <label>JS</label>
                <textarea name="module_js" class="code-editor"><?php echo h($data['module_js']); ?></textarea>
            </div>
        </div>
    </details>

    <div class="form-footer">
        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
    </div>
</form>
EOD;
        @file_put_contents(MODULES_DIR . $module_id . '.php', $code);
    }
    header('Location: ' . ADMIN_FILE);
    exit;
}

function get_global_settings() {
    return file_exists(GLOBAL_SETTINGS_FILE) ? json_decode(file_get_contents(GLOBAL_SETTINGS_FILE), true) : [];
}

function save_global_settings($settings) {
    return file_put_contents(GLOBAL_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));
}

function get_module_settings() {
    if (!file_exists(SETTINGS_FILE)) return [];
    if (function_exists('opcache_invalidate')) @opcache_invalidate(SETTINGS_FILE, true);
    $settings = require(SETTINGS_FILE);
    return is_array($settings) ? $settings : [];
}

function save_settings_file($settings) {
    $content = "<?php\nreturn " . var_export($settings, true) . ";\n";
    return file_put_contents(SETTINGS_FILE, $content);
}

function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_bytes($bytes) {
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function clamp_int($val, $min, $max, $fallback) {
    if ($val === null || $val === '') return $fallback;
    $v = (int)$val;
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function redirect_with_message($type, $message, $extra = []) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $message];
    $params = array_diff_key($_GET, array_flip(['action', 'delete_module', 'delete_js_plugin', 'message']));
    if ($extra) $params = array_merge($params, $extra);
    header('Location: ' . ADMIN_FILE . '?' . http_build_query($params));
    exit;
}

function get_block_content($file, $start, $end) {
    if (!file_exists($file)) return null;
    $c = file_get_contents($file);
    $s = strpos($c, $start);
    if ($s === false) return null;
    $e = strpos($c, $end, $s);
    if ($e === false) return null;
    return trim(substr($c, $s + strlen($start), $e - $s - strlen($start)));
}

function replace_block_content_full($content, $start, $end, $new) {
    $s_pos = strpos($content, $start);
    if ($s_pos === false) return false;
    $e_pos = strpos($content, $end, $s_pos);
    if ($e_pos === false) return false;
    
    // Simple replacement keeping markers
    $replacement = $start . "\n" . $new . "\n" . $end;
    return substr_replace($content, $replacement, $s_pos, $e_pos + strlen($end) - $s_pos);
}

function load_module_data($target, $json_file, $start, $end, $defaults, $parser) {
    $data = [];
    $parsed = get_block_content($target, $start, $end);
    if ($parsed) $data = $parser($parsed);
    
    if (file_exists($json_file)) {
        $j = json_decode(file_get_contents($json_file), true);
        if ($j) $data = array_merge($data, $j); // JSON overrides parse
    }
    return array_merge($defaults, $data);
}

function update_target_file($target, $start, $end, $html) {
    if (!is_writable($target)) return false;
    $content = file_get_contents($target);
    $new = replace_block_content_full($content, $start, $end, $html);
    return $new ? file_put_contents($target, $new) : false;
}

function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response($payload, $status = 200) {
    if (function_exists('http_response_code')) {
        http_response_code((int) $status);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_module_id($id) {
    return strtolower(preg_replace('/[^a-z0-9-]/', '', (string) $id));
}

function sanitize_action_id($action) {
    return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $action));
}

function is_public_module_action($action) {
    if ($action === 'module_ping' || $action === 'run_worker') {
        return true;
    }
    $G = $GLOBALS['G'] ?? [];
    $action = strtolower((string)$action);

    // Проверка через тумблеры (по умолчанию разрешены, если настройки еще не сохранены)
    if (($G['allow_public_tg'] ?? 1) && preg_match('/^tg($|[_-])/', $action)) return true;
    if (($G['allow_public_webhook'] ?? 1) && preg_match('/^webhook($|[_-])/', $action)) return true;
    if (($G['allow_public_callback'] ?? 1) && preg_match('/^callback($|[_-])/', $action)) return true;
    if (($G['allow_public_cron'] ?? 1) && preg_match('/^cron($|[_-])/', $action)) return true;
    if (($G['allow_public_api'] ?? 1) && preg_match('/^api($|[_-])/', $action)) return true;
    if (($G['allow_public_run'] ?? 1) && preg_match('/^run($|[_-])/', $action)) return true;

    $whitelist_raw = $G['public_actions_whitelist'] ?? '';
    if ($whitelist_raw !== '') {
        $whitelist = array_map('trim', explode(',', $whitelist_raw));
        return in_array($action, $whitelist, true);
    }
    return false;
}

function is_module_public_enabled_for_action($module_id, $action, $global_settings) {
    $module_id = sanitize_module_id($module_id);
    $action = sanitize_action_id($action);
    if ($module_id === '') {
        return false;
    }

    $public_map = is_array($global_settings['module_public_access_map'] ?? null) ? $global_settings['module_public_access_map'] : [];
    if (array_key_exists($module_id, $public_map)) {
        return (string) $public_map[$module_id] !== '0';
    }

    // Backward compatibility: keep legacy public callback working even if map is empty.
    if ($module_id === 'gold-analyzer' && $action === 'tg_feedback') {
        return true;
    }

    // Fallback to default module availability for manager-like access.
    $module_access_map = is_array($global_settings['module_access_map'] ?? null) ? $global_settings['module_access_map'] : [];
    return !isset($module_access_map[$module_id]) || (string) $module_access_map[$module_id] !== '0';
}

// (Конец: Базовые функции)

// (Начало: Логика)

if (!is_installed()) setup_environment();

$G = get_global_settings();
$PASS = $G['password'] ?? '8098';
$ACCENT = $G['accent_color_hex'] ?? '#007acc';
$TITLE = $G['admin_title'] ?? 'Panel';
$PLUGINS = $G['js_plugins'] ?? [];

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . ADMIN_FILE);
    exit;
}

// Public webhook passthrough for module callbacks that must work without admin session.
$public_module_id = sanitize_module_id($_GET['module'] ?? '');
$public_action = sanitize_action_id($_GET['action'] ?? '');
$is_cron_call = isset($_GET['cron_token']);

if ($public_module_id !== '' && ($public_action !== '' || $is_cron_call) && ($is_cron_call || is_public_module_action($public_action))) {
    $public_file = MODULES_DIR . $public_module_id . '.php';
    $public_enabled = is_module_public_enabled_for_action($public_module_id, $public_action, $G);
    $public_exists = file_exists($public_file);

    if ($public_action === 'module_ping') {
        json_response([
            'ok' => true,
            'module' => $public_module_id,
            'action' => $public_action,
            'public_enabled' => $public_enabled ? 1 : 0,
            'module_file_exists' => $public_exists ? 1 : 0,
            'timestamp' => date('c')
        ]);
    }

    if (!$public_enabled) {
        json_response([
            'ok' => false,
            'error' => 'module_public_access_disabled',
            'module' => $public_module_id,
            'action' => $public_action
        ], 403);
    }

    if (!$public_exists) {
        json_response([
            'ok' => false,
            'error' => 'module_not_found',
            'module' => $public_module_id
        ], 404);
    }

    $prev_len = (int) ob_get_length();
    if (!defined('IS_ADMIN')) {
        define('IS_ADMIN', true);
    }
    
    // Освобождаем блокировку сессии, так как боту она не нужна, 
    // это позволит обрабатывать нажатия кнопок параллельно и быстро.
    session_write_close();
    
    include $public_file;

    $buffer = (string) ob_get_contents();
    $delta = substr($buffer, $prev_len);
    if (trim((string) $delta) === '' && !headers_sent()) {
        json_response([
            'ok' => true,
            'module' => $public_module_id,
            'action' => $public_action,
            'status' => 'accepted_no_output'
        ]);
    }
    exit;
}

// Глобальная проверка прав
function can($key) {
    $perms = $_SESSION['auth_perms'] ?? [];
    if (!empty($perms['all'])) return true;
    if (!empty($perms[$key])) return true;

    // Full module access grants all module-related actions and section access.
    if (!empty($perms['modules_full_access'])) {
        if ($key === 'modules_view' || strpos($key, 'modules_') === 0) {
            return true;
        }
    }

    // If manager has any specific module permission, allow opening module section.
    if ($key === 'modules_view') {
        foreach ($perms as $k => $v) {
            if (!$v) continue;
            if (strpos($k, 'module_') === 0) return true;
        }
    }
    return false;
}

function can_module($id) {
    if (!can('modules_view')) return false;
    $perms = $_SESSION['auth_perms'] ?? [];
    if (!empty($perms['all'])) return true;
    if (!empty($perms['modules_full_access'])) return true;
    $id = trim((string) $id);
    $module_access_map = is_array($GLOBALS['G']['module_access_map'] ?? null) ? $GLOBALS['G']['module_access_map'] : [];
    $default_allowed = !isset($module_access_map[$id]) || (string) $module_access_map[$id] !== '0';
    $has_specific = false;
    foreach ($perms as $k => $v) {
        if ($v && strpos($k, 'module_') === 0) { $has_specific = true; break; }
    }
    $has_explicit_access = !empty($perms['module_' . $id]) || !empty($perms['module_full_' . $id]);
    if ($has_explicit_access) return true;
    if (!$has_specific) return $default_allowed;
    return false;
}

function can_module_action($mid, $perm) {
    if (can($perm)) return true;
    if (empty($_SESSION['auth_role']) || $_SESSION['auth_role'] !== 'manager') return false;

    $perms = $_SESSION['auth_perms'] ?? [];
    if (!empty($perms['all']) || !empty($perms['modules_full_access'])) return true;
    $mid = trim((string) $mid);
    if ($mid !== '' && !empty($perms['module_full_' . $mid])) return true;
    return false;
}

function require_module_action_or_redirect($mid, $perm, $page = 'modules') {
    if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'manager' && !can_module_action($mid, $perm)) {
        redirect_with_message('error', 'Доступ запрещён', ['page' => $page]);
    }
}

function require_perm_or_redirect($perm, $page = 'panel') {
    if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'manager' && !can($perm)) {
        redirect_with_message('error', 'Доступ запрещён', ['page' => $page]);
    }
}

function normalize_manager_perms($perms) {
    $map = [
        'panel' => 'panel_view',
        'modules' => 'modules_view',
        'js' => 'js_view',
        'settings' => 'settings_view',
        'backup' => 'backup_download',
        'modules_all_access' => 'modules_full_access'
    ];
    foreach ($map as $old => $new) {
        if (!isset($perms[$new]) && !empty($perms[$old])) {
            $perms[$new] = 1;
        }
    }
    return $perms;
}

function require_module_access($mid, $page = 'modules') {
    if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'manager' && !can_module($mid)) {
        redirect_with_message('error', 'Доступ к модулю запрещён', ['page' => $page]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    $user = trim($_POST['login_user'] ?? '');
    $pwd = $_POST['login_pass'];

    // Главный админ: только пароль, без логина
    if ($user === '') {
        if ($pwd === $PASS) {
            $_SESSION['auth'] = true;
            $_SESSION['auth_role'] = 'admin';
            $_SESSION['auth_name'] = 'Administrator';
            $_SESSION['auth_perms'] = ['all'=>1];
            header('Location: ' . ADMIN_FILE);
            exit;
        }
        $login_error = "Неверный пароль";
    } else {
        // Менеджеры: логин (id или имя) + пароль
        $managers = $G['managers'] ?? [];
        $matched = false;
        foreach ($managers as $mid => $mgr) {
            $id_match = (strcasecmp($mid, $user) === 0);
            $name_match = !empty($mgr['name']) && (strcasecmp($mgr['name'], $user) === 0);
            if ($id_match || $name_match) {
                $matched = true;
                if (!empty($mgr['password']) && $pwd === $mgr['password']) {
                    $_SESSION['auth'] = true;
                    $_SESSION['auth_role'] = 'manager';
                    $_SESSION['auth_name'] = $mgr['name'] ?? $mid;
                    $_SESSION['auth_id'] = $mid;
                    $_SESSION['auth_perms'] = normalize_manager_perms($mgr['perms'] ?? []);
                    header('Location: ' . ADMIN_FILE);
                    exit;
                }
                break;
            }
        }
        $login_error = $matched ? "Неверный пароль" : "Неверный логин или пароль";
    }
}

if (empty($_SESSION['auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход в админку</title>
        <style>
            :root {
                --accent: <?php echo $ACCENT; ?>;
                --bg-0: #0f1116;
                --bg-1: #151922;
                --panel: rgba(23, 27, 37, 0.92);
                --panel-border: rgba(255, 255, 255, 0.08);
                --text: #e6edf3;
                --text-dim: #9aa4b2;
                --shadow: 0 30px 80px rgba(0,0,0,0.55);
            }
            body {
                margin: 0;
                height: 100vh;
                display: grid;
                place-items: center;
                font-family: "Space Grotesk", "Segoe UI", -apple-system, sans-serif;
                color: var(--text);
                background:
                    radial-gradient(1200px 600px at 20% -10%, rgba(0, 122, 204, 0.22), transparent 60%),
                    radial-gradient(900px 500px at 110% 10%, rgba(0, 242, 255, 0.15), transparent 55%),
                    linear-gradient(160deg, var(--bg-0), var(--bg-1));
                overflow: hidden;
            }
            body::before {
                content: "";
                position: fixed;
                inset: 0;
                background-image:
                    linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
                background-size: 40px 40px;
                opacity: 0.35;
                pointer-events: none;
            }
            .login-wrap {
                position: relative;
                width: min(420px, 92vw);
            }
            .login-glow {
                position: absolute;
                inset: -30% -20%;
                background: radial-gradient(circle at 30% 30%, rgba(0, 122, 204, 0.35), transparent 55%);
                filter: blur(28px);
                z-index: 0;
            }
            .login-box {
                position: relative;
                z-index: 1;
                padding: 2.2rem 2rem 2rem;
                background: var(--panel);
                border: 1px solid var(--panel-border);
                border-radius: 16px;
                box-shadow: var(--shadow);
                backdrop-filter: blur(10px);
            }
            .login-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 1.6rem;
            }
            .login-brand {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .brand-mark {
                width: 38px;
                height: 38px;
                border-radius: 12px;
                background:
                    linear-gradient(135deg, var(--accent), rgba(0, 242, 255, 0.65));
                box-shadow: 0 10px 30px rgba(0, 122, 204, 0.35);
                position: relative;
            }
            .brand-mark::after {
                content: "";
                position: absolute;
                inset: 8px;
                border-radius: 8px;
                border: 1px solid rgba(255,255,255,0.55);
            }
            h2 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                letter-spacing: 0.4px;
            }
            .login-sub {
                font-size: 12px;
                color: var(--text-dim);
                margin-top: 2px;
            }
            .login-meta {
                font-size: 11px;
                color: var(--text-dim);
                text-transform: uppercase;
                letter-spacing: 1.6px;
            }
            .field {
                position: relative;
                margin-bottom: 1.1rem;
            }
            .field input {
                width: 100%;
                padding: 12px 44px 12px 14px;
                background: rgba(9, 12, 18, 0.7);
                border: 1px solid rgba(255,255,255,0.08);
                color: var(--text);
                box-sizing: border-box;
                font-size: 14px;
                border-radius: 10px;
                outline: none;
                transition: border-color .2s ease, box-shadow .2s ease;
            }
            .field input:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px rgba(0, 122, 204, 0.2);
            }
            .field .icon {
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: var(--text-dim);
                font-size: 16px;
                pointer-events: none;
            }
            .actions {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            button {
                flex: 1;
                padding: 12px;
                background: linear-gradient(135deg, var(--accent), #10b8ff);
                color: white;
                border: none;
                font-weight: 600;
                cursor: pointer;
                border-radius: 10px;
                letter-spacing: 0.3px;
                transition: transform .15s ease, filter .15s ease;
            }
            button:hover {
                filter: brightness(1.05);
                transform: translateY(-1px);
            }
            .hint {
                font-size: 11px;
                color: var(--text-dim);
                margin-top: 8px;
            }
            .err {
                color: #ff8f7a;
                font-size: 12px;
                margin-top: 12px;
            }
            @media (max-width: 480px) {
                .login-box { padding: 1.8rem 1.4rem; }
                .login-meta { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="login-wrap">
            <div class="login-glow"></div>
            <div class="login-box">
                <div class="login-header">
                    <div class="login-brand">
                        <div class="brand-mark"></div>
                        <div>
                            <h2><?php echo h($TITLE); ?></h2>
                            <div class="login-sub">Secure Admin Access</div>
                        </div>
                    </div>
                    <div class="login-meta">v<?php echo h(ADMIN_VERSION); ?></div>
                </div>
                <form method="POST">
                    <div class="field">
                        <input type="text" name="login_user" placeholder="Логин (для менеджера)" autocomplete="username">
                        <span class="icon">@</span>
                    </div>
                    <div class="field">
                        <input type="password" name="login_pass" placeholder="Пароль" autofocus required>
                        <span class="icon">●</span>
                    </div>
                    <div class="actions">
                        <button type="submit">ВОЙТИ</button>
                    </div>
                    <div class="hint">Админ входит только по паролю, логин оставьте пустым.</div>
                    <?php if(isset($login_error)) echo "<div class='err'>$login_error</div>"; ?>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Router Logic
$mod_settings = get_module_settings();
$nav_mods = $mod_settings;
if ($menu_modules_sort === 'alpha') {
    uasort($nav_mods, function($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });
}

// Обновите этот блок в начале admin.php
if (isset($_POST['action']) && $_POST['action'] === 'save_global') {
    require_perm_or_redirect('settings_save', 'settings');
    $G['admin_title'] = $_POST['admin_title'];
    $G['accent_color_hex'] = $_POST['accent_color'];
    
    // Базовые настройки
    $G['editor_font_size'] = $_POST['editor_font_size'] ?? '14';
    $G['editor_word_wrap'] = isset($_POST['editor_word_wrap']) ? 1 : 0;
    $G['timezone'] = $_POST['timezone'] ?? 'Europe/Chisinau';
    $G['session_timeout'] = $_POST['session_timeout'] ?? 30;

// Персонализация UI
    $G['theme_preset'] = $_POST['theme_preset'] ?? 'dark';
    $G['ui_font_size'] = clamp_int($_POST['ui_font_size'] ?? null, 11, 18, 13);
    $G['ui_radius'] = clamp_int($_POST['ui_radius'] ?? null, 2, 14, 6);
    $G['ui_shadow'] = isset($_POST['ui_shadow']) ? 1 : 0;
    $G['ui_blur'] = isset($_POST['ui_blur']) ? 1 : 0;
    $G['ui_animations'] = isset($_POST['ui_animations']) ? 1 : 0;
    $G['ui_compact'] = isset($_POST['ui_compact']) ? 1 : 0;
    $G['ui_density'] = $_POST['ui_density'] ?? 'cozy';
    $G['sidebar_width'] = clamp_int($_POST['sidebar_width'] ?? null, 180, 320, 220);
    $G['topbar_enabled'] = isset($_POST['topbar_enabled']) ? 1 : 0;
    $G['default_page'] = $_POST['default_page'] ?? 'panel';
    $G['bg_style'] = $_POST['bg_style'] ?? 'solid';
    $G['bg_grad_from'] = $_POST['bg_grad_from'] ?? '#0f172a';
    $G['bg_grad_to'] = $_POST['bg_grad_to'] ?? '#1f2937';
    $G['bg_image'] = $_POST['bg_image'] ?? '';
    $G['noise'] = isset($_POST['noise']) ? 1 : 0;
    $G['noise_opacity'] = clamp_int($_POST['noise_opacity'] ?? 8, 0, 30, 8);
    $G['glass_panels'] = isset($_POST['glass_panels']) ? 1 : 0;
    $G['accent_glow'] = isset($_POST['accent_glow']) ? 1 : 0;
    $G['content_max_width'] = clamp_int($_POST['content_max_width'] ?? 1280, 960, 1600, 1280);
    $G['anim_speed'] = $_POST['anim_speed'] ?? 'normal';

    // Меню: видимость и подписи
    $G['nav_show_panel'] = isset($_POST['nav_show_panel']) ? 1 : 0;
    $G['nav_show_modules'] = isset($_POST['nav_show_modules']) ? 1 : 0;
    $G['nav_show_js'] = isset($_POST['nav_show_js']) ? 1 : 0;
    $G['nav_show_settings'] = isset($_POST['nav_show_settings']) ? 1 : 0;
    $G['nav_label_panel'] = trim($_POST['nav_label_panel'] ?? 'Панель');
    $G['nav_label_modules'] = trim($_POST['nav_label_modules'] ?? 'Модули');
    $G['nav_label_js'] = trim($_POST['nav_label_js'] ?? 'JS Плагины');
    $G['nav_label_settings'] = trim($_POST['nav_label_settings'] ?? 'Настройки');
    $menu_sort = $_POST['menu_modules_sort'] ?? 'manual';
    $G['menu_modules_sort'] = in_array($menu_sort, ['manual','alpha'], true) ? $menu_sort : 'manual';
    $G['menu_compact'] = isset($_POST['menu_compact']) ? 1 : 0;
    $G['menu_modules_collapsed'] = isset($_POST['menu_modules_collapsed']) ? 1 : 0;
    $menu_style = $_POST['menu_style'] ?? 'classic';
    $G['menu_style'] = in_array($menu_style, ['classic','rail','ribbon','neon','metro'], true) ? $menu_style : 'classic';

    // Кастомные ссылки в меню
    $titles = $_POST['custom_link_title'] ?? [];
    $urls = $_POST['custom_link_url'] ?? [];
    $targets = $_POST['custom_link_target'] ?? [];
    $custom_links = [];
    if (is_array($titles) && is_array($urls)) {
        foreach ($titles as $i => $t) {
            $title = trim($t);
            $url = trim($urls[$i] ?? '');
            if ($title && $url) {
                $custom_links[] = [
                    'title' => $title,
                    'url' => $url,
                    'target' => in_array($targets[$i] ?? '', ['_blank','_self'], true) ? $targets[$i] : '_blank'
                ];
            }
        }
    }
    $G['nav_custom_links'] = $custom_links;

    // Цвета темы
    $G['color_bg_app'] = $_POST['color_bg_app'] ?? '';
    $G['color_bg_sidebar'] = $_POST['color_bg_sidebar'] ?? '';
    $G['color_bg_panel'] = $_POST['color_bg_panel'] ?? '';
    $G['color_text'] = $_POST['color_text'] ?? '';
    $G['color_text_dim'] = $_POST['color_text_dim'] ?? '';
    $G['color_border'] = $_POST['color_border'] ?? '';

    $G['public_actions_whitelist'] = $_POST['public_actions_whitelist'] ?? '';
    $G['allow_public_tg'] = isset($_POST['allow_public_tg']) ? 1 : 0;
    $G['allow_public_webhook'] = isset($_POST['allow_public_webhook']) ? 1 : 0;
    $G['allow_public_callback'] = isset($_POST['allow_public_callback']) ? 1 : 0;
    $G['allow_public_cron'] = isset($_POST['allow_public_cron']) ? 1 : 0;
    $G['allow_public_api'] = isset($_POST['allow_public_api']) ? 1 : 0;
    $G['allow_public_run'] = isset($_POST['allow_public_run']) ? 1 : 0;

    // Дополнительные
    $G['custom_css'] = $_POST['custom_css'] ?? '';
    $G['custom_logo'] = $_POST['custom_logo'] ?? '';
    $G['show_kpi'] = isset($_POST['show_kpi']) ? 1 : 0;
    $G['show_recent'] = isset($_POST['show_recent']) ? 1 : 0;
    
    if (!empty($_POST['new_pass'])) $G['password'] = $_POST['new_pass'];
    save_global_settings($G);
    redirect_with_message('success', 'Настройки системы обновлены', ['page' => 'settings']);
}

// Быстрый выбор темы из топбара
if (isset($_POST['action']) && $_POST['action'] === 'quick_theme') {
$available_themes = ['dark','graphite','midnight','contrast','dracula','monokai','obsidian','ocean','cyber'];
    $req = $_POST['theme'] ?? '';
    if (in_array($req, $available_themes, true)) {
        $G['theme_preset'] = $req;
        save_global_settings($G);
        redirect_with_message('success', 'Тема переключена: ' . ucfirst($req));
    } else {
        redirect_with_message('error', 'Неизвестная тема');
    }
}

// ЛОГИКА БЭКАПА (Вставьте это в начале, до HTML)
if (isset($_POST['action']) && $_POST['action'] === 'download_backup') {
    require_perm_or_redirect('backup_download', 'settings');
    $backup = [
        'global' => $G,
        'modules' => get_module_settings(),
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => []
    ];
    // Собираем все JSON данные модулей
    foreach (glob(DATA_DIR . '*.json') as $filename) {
        $backup['data'][basename($filename)] = json_decode(file_get_contents($filename), true);
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="admin_backup_'.date('Y-m-d').'.json"');
    echo json_encode($backup, JSON_PRETTY_PRINT);
    exit;
}

// Modules Logic
if (isset($_POST['action']) && $_POST['action'] === 'add_module') {
    require_perm_or_redirect('modules_add', 'modules');
    $id = strtolower(preg_replace('/[^a-z0-9-]/', '', $_POST['mid'] ?? ''));
    $name = trim($_POST['mname'] ?? '');
    if ($id && $name && !isset($mod_settings[$id])) {
        $mod_settings = [$id => ['name' => $name, 'file' => $id . '.php']] + $mod_settings;
        save_settings_file($mod_settings);

        $target = MODULES_DIR . $id . '.php';
        $uploaded = $_FILES['module_file'] ?? null;
        $used_upload = false;
        if ($uploaded && isset($uploaded['tmp_name']) && is_uploaded_file($uploaded['tmp_name'])) {
            $ext = strtolower(pathinfo($uploaded['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext === 'php') {
                if (@move_uploaded_file($uploaded['tmp_name'], $target)) {
                    $used_upload = true;
                }
            }
        }
        if (!$used_upload) {
            file_put_contents($target, "<?php\nif(!defined('IS_ADMIN')) die;\n?>\n<h3>New Module</h3>");
        }
        redirect_with_message('success', $used_upload ? 'Модуль создан из файла' : 'Модуль создан', ['page' => 'modules']);
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'edit_module_meta') {
    require_module_action_or_redirect($_POST['old_id'] ?? '', 'modules_edit_meta', 'modules');
    $old_id = strtolower(preg_replace('/[^a-z0-9-]/', '', $_POST['old_id'] ?? ''));
    require_module_access($old_id, 'modules');
    $new_id = strtolower(preg_replace('/[^a-z0-9-]/', '', $_POST['mid'] ?? ''));
    $new_name = trim($_POST['mname'] ?? '');
    if (!$old_id || !$new_id || !$new_name || !isset($mod_settings[$old_id])) {
        redirect_with_message('error', 'Некорректные данные', ['page' => 'modules']);
    }
    if ($new_id !== $old_id && isset($mod_settings[$new_id])) {
        redirect_with_message('error', 'ID уже занят', ['page' => 'modules']);
    }

    $old_file = MODULES_DIR . $old_id . '.php';
    $new_file = MODULES_DIR . $new_id . '.php';
    $old_json = DATA_DIR . $old_id . '.json';
    $new_json = DATA_DIR . $new_id . '.json';
    $old_uploads = UPLOADS_DIR . $old_id;
    $new_uploads = UPLOADS_DIR . $new_id;

    $uploaded = $_FILES['module_file'] ?? null;
    if ($uploaded && isset($uploaded['tmp_name']) && is_uploaded_file($uploaded['tmp_name'])) {
        $ext = strtolower(pathinfo($uploaded['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'php') {
            redirect_with_message('error', 'Файл должен быть .php', ['page' => 'modules']);
        }
    }

    // Rename related files/dirs if ID changed
    if ($new_id !== $old_id) {
        if (file_exists($old_json)) @rename($old_json, $new_json);
        if (is_dir($old_uploads)) @rename($old_uploads, $new_uploads);
        if (file_exists($old_file) && (!isset($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name']))) {
            @rename($old_file, $new_file);
        }
    }

    // Save uploaded module file if provided
    if ($uploaded && isset($uploaded['tmp_name']) && is_uploaded_file($uploaded['tmp_name'])) {
        @move_uploaded_file($uploaded['tmp_name'], $new_file);
        if ($new_id !== $old_id && file_exists($old_file)) @unlink($old_file);
    }

    unset($mod_settings[$old_id]);
    $mod_settings[$new_id] = ['name' => $new_name, 'file' => $new_id . '.php'];
    save_settings_file($mod_settings);
    if ($new_id !== $old_id) {
        $module_access_map = is_array($G['module_access_map'] ?? null) ? $G['module_access_map'] : [];
        if (array_key_exists($old_id, $module_access_map)) {
            $module_access_map[$new_id] = $module_access_map[$old_id];
            unset($module_access_map[$old_id]);
            $G['module_access_map'] = $module_access_map;
        }
        $module_public_access_map = is_array($G['module_public_access_map'] ?? null) ? $G['module_public_access_map'] : [];
        if (array_key_exists($old_id, $module_public_access_map)) {
            $module_public_access_map[$new_id] = $module_public_access_map[$old_id];
            unset($module_public_access_map[$old_id]);
            $G['module_public_access_map'] = $module_public_access_map;
        }
        save_global_settings($G);
    }
    redirect_with_message('success', 'Параметры модуля обновлены', ['page' => 'modules']);
}
if (isset($_POST['action']) && $_POST['action'] === 'save_module_default_access') {
    $mid = strtolower(preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['mid'] ?? '')));
    if ($mid === '' || !isset($mod_settings[$mid])) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'module_not_found'], 404);
        }
        redirect_with_message('error', 'Модуль не найден', ['page' => 'modules']);
    }
    $module_access_map = is_array($G['module_access_map'] ?? null) ? $G['module_access_map'] : [];
    $module_access_map[$mid] = isset($_POST['module_enabled']) ? 1 : 0;
    $G['module_access_map'] = $module_access_map;
    save_global_settings($G);
    if (is_ajax_request()) {
        json_response([
            'ok' => true,
            'module' => $mid,
            'module_enabled' => $module_access_map[$mid]
        ]);
    }
    redirect_with_message('success', 'Доступ к модулю по умолчанию обновлён', ['page' => 'modules']);
}
if (isset($_POST['action']) && $_POST['action'] === 'save_module_public_access') {
    $mid = strtolower(preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['mid'] ?? '')));
    if ($mid === '' || !isset($mod_settings[$mid])) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'module_not_found'], 404);
        }
        redirect_with_message('error', 'Модуль не найден', ['page' => 'modules']);
    }
    $module_public_access_map = is_array($G['module_public_access_map'] ?? null) ? $G['module_public_access_map'] : [];
    $module_public_access_map[$mid] = isset($_POST['module_public_enabled']) ? 1 : 0;
    $G['module_public_access_map'] = $module_public_access_map;
    save_global_settings($G);
    if (is_ajax_request()) {
        json_response([
            'ok' => true,
            'module' => $mid,
            'module_public_enabled' => $module_public_access_map[$mid]
        ]);
    }
    redirect_with_message('success', 'Публичный доступ к callback обновлён', ['page' => 'modules']);
}
if (isset($_POST['action']) && $_POST['action'] === 'save_module_access') {
    $mid = strtolower(preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['mid'] ?? '')));
    if ($mid === '' || !isset($mod_settings[$mid])) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'module_not_found'], 404);
        }
        redirect_with_message('error', 'Модуль не найден', ['page' => 'modules']);
    }

    $managersCfg = is_array($G['managers'] ?? null) ? $G['managers'] : [];
    if (empty($managersCfg)) {
        if (is_ajax_request()) {
            json_response(['ok' => false, 'error' => 'managers_not_found'], 400);
        }
        redirect_with_message('error', 'Менеджеры не созданы', ['page' => 'modules']);
    }

    $selected = [];
    $posted = $_POST['manager_full'] ?? [];
    if (is_array($posted)) {
        foreach ($posted as $mgrIdRaw) {
            $mgrId = strtolower(preg_replace('/[^a-z0-9-]/', '', (string) $mgrIdRaw));
            if ($mgrId !== '') {
                $selected[$mgrId] = true;
            }
        }
    }

    foreach ($managersCfg as $mgrId => &$mgr) {
        if (!is_array($mgr)) {
            continue;
        }
        $perms = normalize_manager_perms($mgr['perms'] ?? []);
        $perms['module_full_' . $mid] = !empty($selected[$mgrId]) ? 1 : 0;
        $mgr['perms'] = $perms;
    }
    unset($mgr);

    $G['managers'] = $managersCfg;
    save_global_settings($G);
    if (is_ajax_request()) {
        json_response([
            'ok' => true,
            'module' => $mid,
            'updated_managers' => array_keys($selected)
        ]);
    }
    redirect_with_message('success', 'Права доступа к модулю обновлены', ['page' => 'modules']);
}
if (isset($_POST['action']) && $_POST['action'] === 'edit_code_mod') {
    require_module_action_or_redirect($_POST['mid'] ?? '', 'modules_edit_code', 'modules');
    require_module_access($_POST['mid'] ?? '', 'modules');
    file_put_contents(MODULES_DIR . $_POST['mid'] . '.php', $_POST['code']);
    redirect_with_message('success', 'Код сохранён', ['page' => 'modules', 'edit_module' => $_POST['mid']]);
}
if (isset($_GET['del_mod'])) {
    require_module_action_or_redirect($_GET['del_mod'] ?? '', 'modules_delete', 'modules');
    $id = $_GET['del_mod'];
    require_module_access($id, 'modules');
    @unlink(MODULES_DIR . $id . '.php');
    @unlink(DATA_DIR . $id . '.json');
    rrmdir(UPLOADS_DIR . $id);
    unset($mod_settings[$id]);
    save_settings_file($mod_settings);
    $module_access_map = is_array($G['module_access_map'] ?? null) ? $G['module_access_map'] : [];
    if (array_key_exists($id, $module_access_map)) {
        unset($module_access_map[$id]);
        $G['module_access_map'] = $module_access_map;
    }
    $module_public_access_map = is_array($G['module_public_access_map'] ?? null) ? $G['module_public_access_map'] : [];
    if (array_key_exists($id, $module_public_access_map)) {
        unset($module_public_access_map[$id]);
        $G['module_public_access_map'] = $module_public_access_map;
    }
    save_global_settings($G);
    redirect_with_message('success', 'Модуль удалён', ['page' => 'modules']);
}

// JS Logic
if (isset($_POST['js_action'])) {
    $id = $_POST['pid'];
    if ($_POST['js_action'] === 'add' && $id) {
        require_perm_or_redirect('js_add', 'js');
        $G['js_plugins'][$id] = ['name' => $_POST['pname'], 'file' => $id . '.js'];
        file_put_contents(JS_PLUGINS_DIR . $id . '.js', $_POST['code']);
    } elseif ($_POST['js_action'] === 'edit') {
        require_perm_or_redirect('js_edit', 'js');
        file_put_contents(JS_PLUGINS_DIR . $id . '.js', $_POST['code']);
        if (!empty($_POST['pname'])) $G['js_plugins'][$id]['name'] = $_POST['pname'];
    }
    save_global_settings($G);
    compile_plugins_bundle($G);
    redirect_with_message('success', 'Плагин сохранён', ['page' => 'js']);
}
if (isset($_GET['del_js'])) {
    require_perm_or_redirect('js_delete', 'js');
    $id = $_GET['del_js'];
    @unlink(JS_PLUGINS_DIR . $id . '.js');
    unset($G['js_plugins'][$id]);
    save_global_settings($G);
    compile_plugins_bundle($G);
    redirect_with_message('success', 'Плагин удалён', ['page' => 'js']);
}

// Save managers (roles)
if (isset($_POST['action']) && $_POST['action'] === 'save_managers') {
    require_perm_or_redirect('managers_manage', 'settings');
    $json = $_POST['managers_json'] ?? '{}';
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $normalized = [];
        foreach ($decoded as $mid => $mgr) {
            $mid = strtolower(preg_replace('/[^a-z0-9-]/', '', (string) $mid));
            if ($mid === '' || !is_array($mgr)) continue;
            $perms_in = is_array($mgr['perms'] ?? null) ? $mgr['perms'] : [];
            $perms_in = normalize_manager_perms($perms_in);
            $perms_out = [];
            foreach ($perms_in as $k => $v) {
                $perms_out[(string)$k] = $v ? 1 : 0;
            }
            $normalized[$mid] = [
                'name' => trim((string) ($mgr['name'] ?? $mid)),
                'password' => (string) ($mgr['password'] ?? ''),
                'perms' => $perms_out
            ];
        }
        $G['managers'] = $normalized;
        save_global_settings($G);
        if (!empty($_POST['delete'])) {
            unset($G['managers'][$_POST['delete']]);
            save_global_settings($G);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status'=>'ok']);
        exit;
    }
    echo json_encode(['status'=>'error']);
    exit;
}

$page = $_GET['page'] ?? ($G['default_page'] ?? 'panel');
$active_mod = $_GET['module'] ?? null;
if ($active_mod) $page = 'module';

$page_perm_map = [
    'panel' => 'panel_view',
    'modules' => 'modules_view',
    'js' => 'js_view',
    'settings' => 'settings_view',
    'backup' => 'backup_download',
    'module' => 'modules_view'
];

// Ограничение доступа для менеджеров
if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'manager') {
    $need = $page_perm_map[$page] ?? 'panel_view';
    $denied = !can($need);
    if ($page === 'module' && $active_mod) {
        $denied = !can_module($active_mod);
    }
    if ($denied) {
        $_SESSION['flash'] = ['type'=>'error','text'=>'Доступ запрещён для вашей роли'];
        $page = 'panel';
        $active_mod = null;
    }
}

$theme = $G['theme_preset'] ?? 'dark';
$theme_map = [
    'dark' => ['bg_app'=>'#1e1e1e','bg_sidebar'=>'#252526','bg_panel'=>'#2d2d2d','text'=>'#cccccc','text_dim'=>'#858585','border'=>'#3e3e42'],
    'graphite' => ['bg_app'=>'#16181b','bg_sidebar'=>'#1f2328','bg_panel'=>'#2b3137','text'=>'#d0d7de','text_dim'=>'#8b949e','border'=>'#30363d'],
    'midnight' => ['bg_app'=>'#0f1216','bg_sidebar'=>'#151a21','bg_panel'=>'#1d2430','text'=>'#d6d9e0','text_dim'=>'#8a93a3','border'=>'#2a3240'],
    'contrast' => ['bg_app'=>'#0c0c0c','bg_sidebar'=>'#161616','bg_panel'=>'#1e1e1e','text'=>'#f2f2f2','text_dim'=>'#a0a0a0','border'=>'#3a3a3a'],
    'dracula' => ['bg_app'=>'#1e1f29','bg_sidebar'=>'#282a36','bg_panel'=>'#2c2e3a','text'=>'#f8f8f2','text_dim'=>'#b6b6c6','border'=>'#3a3c4a'],
    'monokai' => ['bg_app'=>'#2c292d','bg_sidebar'=>'#34302f','bg_panel'=>'#3b3635','text'=>'#f8f8f2','text_dim'=>'#a6a6a6','border'=>'#4a4644'],
    'obsidian' => ['bg_app'=>'#121416','bg_sidebar'=>'#181b1f','bg_panel'=>'#1e2227','text'=>'#d8d8da','text_dim'=>'#8f96a3','border'=>'#2a2f36'],
    'ocean' => ['bg_app'=>'#0b1522','bg_sidebar'=>'#0f1c2d','bg_panel'=>'#13243a','text'=>'#d7e2f2','text_dim'=>'#93a4bc','border'=>'#1e2c3f'],
    'cyber' => ['bg_app'=>'#0a0f1a','bg_sidebar'=>'#101726','bg_panel'=>'#131c2f','text'=>'#d0f6ff','text_dim'=>'#8ab7c6','border'=>'#1f2b3c'],
];
$t = $theme_map[$theme] ?? $theme_map['dark'];
$ui_bg_app = $G['color_bg_app'] ?: $t['bg_app'];
$ui_bg_sidebar = $G['color_bg_sidebar'] ?: $t['bg_sidebar'];
$ui_bg_panel = $G['color_bg_panel'] ?: $t['bg_panel'];
$ui_text = $G['color_text'] ?: $t['text'];
$ui_text_dim = $G['color_text_dim'] ?: $t['text_dim'];
$ui_border = $G['color_border'] ?: $t['border'];
$ui_text_bright = '#ffffff';
$mix_base = 'black';

// Меню: настройки
$nav_show_panel = !isset($G['nav_show_panel']) || !empty($G['nav_show_panel']);
$nav_show_modules = !isset($G['nav_show_modules']) || !empty($G['nav_show_modules']);
$nav_show_js = !isset($G['nav_show_js']) || !empty($G['nav_show_js']);
$nav_show_settings = !isset($G['nav_show_settings']) || !empty($G['nav_show_settings']);
$nav_label_panel = $G['nav_label_panel'] ?? 'Панель';
$nav_label_modules = $G['nav_label_modules'] ?? 'Модули';
$nav_label_js = $G['nav_label_js'] ?? 'JS Плагины';
$nav_label_settings = $G['nav_label_settings'] ?? 'Настройки';
$menu_modules_sort = $G['menu_modules_sort'] ?? 'manual';
$menu_compact = !empty($G['menu_compact']);
$menu_modules_collapsed = !empty($G['menu_modules_collapsed']);
$nav_custom_links = $G['nav_custom_links'] ?? [];
$menu_style = $G['menu_style'] ?? 'classic';

$auth_role = $_SESSION['auth_role'] ?? 'guest';
$auth_name = $_SESSION['auth_name'] ?? 'Гость';
$role_label = $auth_role === 'admin' ? 'Главный админ' : ($auth_role === 'manager' ? 'Менеджер' : 'Гость');
$can_panel = can('panel_view');
$can_modules = can('modules_view');
$can_js = can('js_view');
$can_settings = can('settings_view');

$ui_font_size = (int)($G['ui_font_size'] ?? 13);
$ui_radius = (int)($G['ui_radius'] ?? 6);
$ui_shadow = !empty($G['ui_shadow']);
$ui_blur = !empty($G['ui_blur']);
$ui_animations = !empty($G['ui_animations']);
$ui_compact = !empty($G['ui_compact']);
$ui_density = $G['ui_density'] ?? 'cozy';
$sidebar_width = (int)($G['sidebar_width'] ?? 220);
$topbar_enabled = !isset($G['topbar_enabled']) || !empty($G['topbar_enabled']);
$show_kpi = !isset($G['show_kpi']) || !empty($G['show_kpi']);
$show_recent = !isset($G['show_recent']) || !empty($G['show_recent']);
$custom_css = $G['custom_css'] ?? '';
$custom_logo = $G['custom_logo'] ?? '';
$bg_style = $G['bg_style'] ?? 'solid';
$bg_grad_from = $G['bg_grad_from'] ?? '#0f172a';
$bg_grad_to = $G['bg_grad_to'] ?? '#1f2937';
$bg_image = $G['bg_image'] ?? '';
$noise = !empty($G['noise']);
$noise_opacity = (int)($G['noise_opacity'] ?? 8);
$glass_panels = !empty($G['glass_panels']);
$accent_glow = !empty($G['accent_glow']);
$content_max_width = (int)($G['content_max_width'] ?? 1280);
$anim_speed = $G['anim_speed'] ?? 'normal';
$managers = $G['managers'] ?? [];

$density_map = ['compact'=>0.85,'cozy'=>1,'spacious'=>1.15];
$density = $density_map[$ui_density] ?? 1;

$body_classes = [];
if ($ui_compact) $body_classes[] = 'ui-compact';
if (!$ui_animations) $body_classes[] = 'ui-no-anim';
if (!$ui_shadow) $body_classes[] = 'ui-flat';
if ($ui_blur) $body_classes[] = 'ui-blur';
if (!$topbar_enabled) $body_classes[] = 'ui-no-topbar';
if ($noise) $body_classes[] = 'ui-noise';
if ($menu_compact) $body_classes[] = 'nav-compact';
$body_classes[] = 'nav-style-' . preg_replace('/[^a-z0-9_-]/i','', $menu_style);
$anim_speed_map = ['slow'=>1.4,'normal'=>1,'fast'=>0.75];
$anim_factor = $anim_speed_map[$anim_speed] ?? 1;
$page_title = 'Панель управления';
$page_subtitle = '';
if ($active_mod) {
    $page_title = $mod_settings[$active_mod]['name'] ?? $active_mod;
    $page_subtitle = 'Модуль';
} else {
    if ($page === 'modules') { $page_title = 'Модули'; $page_subtitle = 'Управление и порядок'; }
    if ($page === 'js') { $page_title = 'JS плагины'; $page_subtitle = 'Подключения и порядок'; }
    if ($page === 'settings') { $page_title = 'Настройки'; $page_subtitle = 'Система и безопасность'; }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($TITLE); ?></title>
    <link rel="stylesheet" href="<?php echo $PLUGINS_ASSET_WEB_PATH; ?>plugins-bundle.css?v=<?php echo time(); ?>">
    <script src="<?php echo $PLUGINS_ASSET_WEB_PATH; ?>plugins-bundle.js?v=<?php echo time(); ?>"></script>
    <style>
        :root {
            --bg-app: <?php echo $ui_bg_app; ?>;
            --bg-sidebar: <?php echo $ui_bg_sidebar; ?>;
            --bg-panel: <?php echo $ui_bg_panel; ?>;
            --mix-base: <?php echo $mix_base; ?>;
            --bg-panel-2: color-mix(in srgb, var(--bg-panel) 85%, var(--mix-base));
            --bg-input: color-mix(in srgb, var(--bg-panel) 70%, var(--mix-base));
            --border: <?php echo $ui_border; ?>;
            --accent: <?php echo $ACCENT; ?>;
            --accent-hover: color-mix(in srgb, var(--accent) 85%, white);
            --text-main: <?php echo $ui_text; ?>;
            --text-bright: <?php echo $ui_text_bright; ?>;
            --text-dim: <?php echo $ui_text_dim; ?>;
            --danger: #f48771;
            --success: #89d185;
            --font-ui: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --font-code: "Consolas", "Monaco", "Courier New", monospace;
            --radius: <?php echo $ui_radius; ?>px;
            --shadow: <?php echo $ui_shadow ? '0 12px 30px rgba(0,0,0,0.25)' : 'none'; ?>;
            --density: <?php echo $density; ?>;
            --sidebar-w: <?php echo $sidebar_width; ?>px;
        }

        /* RESET & BASE */
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; background: var(--bg-app); color: var(--text-main); font-family: var(--font-ui); font-size: <?php echo $ui_font_size; ?>px; overflow: hidden; height: 100vh; display: flex; <?php
            if ($bg_style === 'gradient') {
                echo "background: linear-gradient(135deg, {$bg_grad_from} 0%, {$bg_grad_to} 100%);";
            } elseif ($bg_style === 'image' && $bg_image) {
                echo "background: url('".h($bg_image)."') center/cover fixed no-repeat, var(--bg-app);";
            }
        ?> }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; padding: 0; margin: 0; }
        h1, h2, h3 { margin: 0; font-weight: 500; color: var(--text-bright); }
        code { font-family: var(--font-code); background: rgba(255,255,255,0.1); padding: 2px 4px; border-radius: 3px; }

        /* SCROLLBARS */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--bg-app); }
        ::-webkit-scrollbar-thumb { background: #424242; border-radius: 0; }
        ::-webkit-scrollbar-thumb:hover { background: #4f4f4f; }

        /* LAYOUT */
        .sidebar { width: var(--sidebar-w); background: var(--bg-sidebar); border-right: 1px solid var(--border); display: flex; flex-direction: column; height: 100vh; flex-shrink: 0; }
        .main-view { flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--bg-app); }
        .content-scroll { flex: 1; overflow-y: auto; padding: calc(18px * var(--density)) calc(24px * var(--density)) calc(28px * var(--density)); max-width: <?php echo $content_max_width; ?>px; width: 100%; margin: 0 auto; }
        
        /* SIDEBAR */
        .brand { padding: 16px 18px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #fff58a; letter-spacing: 1px; border-bottom: 1px solid rgba(255,255,255,0.08); background: linear-gradient(180deg, #2a2a2a, #232323); }
        .nav-section { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .nav-label { padding: 6px 18px; font-size: 11px; text-transform: uppercase; color: #d1d1a9; margin-bottom: 4px; letter-spacing: 0.6px; }
        .nav-item { display: flex; align-items: center; padding: 6px 20px; color: var(--text-main); transition: 0.1s; border-left: 3px solid transparent; cursor: pointer; }
        .nav-item:hover { background: color-mix(in srgb, var(--bg-sidebar) 88%, var(--mix-base)); color: var(--text-bright); }
        .nav-item.active { background: color-mix(in srgb, var(--bg-sidebar) 82%, var(--mix-base)); color: var(--text-bright); border-left-color: var(--accent); }
        .nav-item.logout { color: var(--danger); margin-top: auto; border-top: 1px solid var(--border); padding: 14px 18px; }
        .nav-search { padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .nav-search input { width: 100%; background: var(--bg-input); border: 1px solid var(--border); color: var(--text-main); padding: 8px 10px; border-radius: 4px; font-size: 12px; outline: none; }
        .nav-search input:focus { border-color: var(--accent); }
        .nav-count { padding: 6px 14px; font-size: 11px; color: var(--text-dim); }
        .nav-mini { padding: 8px 14px; font-size: 11px; color: var(--text-dim); }
        .nav-star { margin-left: auto; opacity: 0.4; font-size: 12px; padding: 2px 6px; border-radius: 4px; }
        .nav-item:hover .nav-star { opacity: 0.9; }
        .nav-star.active { color: #ffd966; opacity: 1; }
        body.nav-compact .nav-item { padding: 4px 14px; font-size: 12px; }
        body.nav-compact .nav-label { padding: 4px 14px; font-size: 10px; }
        body.nav-compact .nav-search { padding: 8px 12px; }
        body.nav-compact .nav-search input { padding: 6px 8px; }
        .nav-toggle-btn { background: none; border: 1px solid var(--border); color: var(--text-dim); border-radius: 6px; padding: 4px 8px; font-size: 11px; cursor: pointer; }
        .nav-toggle-btn:hover { color: var(--text-bright); border-color: var(--accent); }
        #modulesWrap.collapsed { display: none; }
        /* NAV STYLES — радикально разные лейауты */
        /* 1) Classic: базовый вид, используем по умолчанию (наследует базовые правила) */

        /* 2) Rail: тонкая колонка с “рельсой” и круглым маркером */
        body.nav-style-rail .nav-section { padding: 6px 0; border-bottom: none; position: relative; }
        body.nav-style-rail .nav-section::before { content:''; position:absolute; left:22px; top:6px; bottom:6px; width:2px; background: color-mix(in srgb, var(--border) 70%, transparent); }
        body.nav-style-rail .nav-item { padding: 8px 14px 8px 32px; border-left: none; position: relative; }
        body.nav-style-rail .nav-item::before { content:''; position:absolute; left:16px; top:50%; transform:translateY(-50%); width:8px; height:8px; border-radius:50%; background: var(--border); box-shadow: 0 0 0 4px color-mix(in srgb, var(--bg-sidebar) 70%, transparent); }
        body.nav-style-rail .nav-item.active::before { background: var(--accent); box-shadow: 0 0 0 6px color-mix(in srgb, var(--accent) 30%, transparent); }
        body.nav-style-rail .nav-item:hover { background: color-mix(in srgb, var(--bg-sidebar) 75%, var(--mix-base)); }

        /* 3) Neon: темный неон с подсвеченной линией слева */
        body.nav-style-neon .nav-section { padding: 8px 0; border-bottom: none; position: relative; }
        body.nav-style-neon .nav-item { 
            margin: 6px 10px; border-left: none; border-radius: 10px; padding: 10px 16px; gap: 10px;
            background: radial-gradient(circle at 0% 50%, color-mix(in srgb, var(--accent) 18%, transparent), transparent 55%),
                        linear-gradient(135deg, color-mix(in srgb, var(--bg-sidebar) 88%, var(--mix-base)), color-mix(in srgb, var(--bg-panel) 82%, var(--mix-base)));
            position: relative; overflow: hidden;
            box-shadow: 0 12px 28px rgba(0,0,0,0.24);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        body.nav-style-neon .nav-item::before {
            content:''; position:absolute; left:0; top:0; bottom:0; width:6px;
            background: linear-gradient(180deg, var(--accent), color-mix(in srgb, var(--accent) 40%, #111));
            box-shadow: 0 0 24px color-mix(in srgb, var(--accent) 40%, transparent);
        }
        body.nav-style-neon .nav-item:hover { transform: translateX(4px); box-shadow: 0 14px 32px color-mix(in srgb, var(--accent) 30%, rgba(0,0,0,0.25)); }
        body.nav-style-neon .nav-item.active { transform: translateX(6px); box-shadow: 0 18px 36px color-mix(in srgb, var(--accent) 45%, rgba(0,0,0,0.3)); }

        /* 4) Ribbon: элементы с ленточкой слева */
        body.nav-style-ribbon .nav-item { position: relative; padding: 8px 18px 8px 26px; border-left: none; overflow: hidden; }
        body.nav-style-ribbon .nav-item::before { content:''; position:absolute; left:0; top:0; bottom:0; width:8px; background: color-mix(in srgb, var(--border) 70%, transparent); clip-path: polygon(0 0, 100% 0, 70% 50%, 100% 100%, 0 100%); }
        body.nav-style-ribbon .nav-item.active::before { background: var(--accent); }
        body.nav-style-ribbon .nav-item:hover::before { background: color-mix(in srgb, var(--accent) 60%, var(--border)); }
        body.nav-style-ribbon .nav-item.active { background: color-mix(in srgb, var(--accent) 12%, var(--bg-sidebar)); }

        /* 5) Metro: плитки-«карточки» в сетке с контрастными цветами */
        body.nav-style-metro .nav-section { padding: 8px 10px; border-bottom: none; }
        body.nav-style-metro .nav-section > a { display:block; } /* make items full width if needed */
        body.nav-style-metro #modulesWrap,
        body.nav-style-metro .nav-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
        body.nav-style-metro .nav-item { 
            border-left: none; border-radius: 12px; padding: 14px 12px; min-height: 56px;
            background: color-mix(in srgb, var(--bg-sidebar) 80%, var(--mix-base));
            box-shadow: 0 10px 22px rgba(0,0,0,0.2);
            flex-direction: column; align-items: flex-start; gap: 6px;
            transition: transform .16s ease, box-shadow .16s ease, background .16s ease;
        }
        body.nav-style-metro .nav-item .nav-star { margin-left: 0; align-self: flex-end; }
        body.nav-style-metro .nav-item:hover { transform: translateY(-3px); box-shadow: 0 14px 28px color-mix(in srgb, var(--accent) 18%, rgba(0,0,0,0.22)); }
        body.nav-style-metro .nav-item.active { background: linear-gradient(130deg, color-mix(in srgb, var(--accent) 22%, var(--bg-sidebar)), color-mix(in srgb, var(--accent) 8%, var(--bg-panel))); box-shadow: 0 16px 32px color-mix(in srgb, var(--accent) 28%, rgba(0,0,0,0.24)); }

        /* TOPBAR */
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--bg-panel); position: sticky; top: 0; z-index: 10; }
        body.ui-no-topbar .topbar { display: none; }
        .topbar-title { font-size: 16px; color: var(--text-bright); font-weight: 600; }
        .topbar-sub { font-size: 11px; color: var(--text-dim); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .quick-jump { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-main); padding: 6px 10px; border-radius: 4px; font-size: 12px; outline: none; min-width: 220px; }
        .quick-jump:focus { border-color: var(--accent); }
        .theme-quick { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; }
        .theme-chip { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-main); padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .theme-chip:hover { border-color: var(--accent); color: var(--text-bright); }
        .theme-chip.active { border-color: var(--accent); box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 40%, transparent); color: var(--text-bright); }
        
        /* UI COMPONENTS */
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
        .panel-header h1 { font-size: 22px; }
        .panel-header h3 { font-size: 18px; }

        .card { background: <?php echo $glass_panels ? "color-mix(in srgb, var(--bg-panel) 70%, transparent)" : "var(--bg-sidebar)"; ?>; border: 1px solid var(--border); margin-bottom: calc(20px * var(--density)); padding: 0; border-radius: var(--radius); box-shadow: var(--shadow); <?php if($glass_panels) echo "backdrop-filter: blur(10px);"; ?> }
        .card-head { padding: 10px 15px; background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); font-weight: 600; font-size: 12px; text-transform: uppercase; border-top-left-radius: var(--radius); border-top-right-radius: var(--radius); }
        .card-body { padding: 15px; }

        /* FORMS */
        .app-form label { display: block; margin-bottom: 6px; color: var(--text-dim); font-size: 12px; font-weight: 600; }
        .app-form input[type=text], .app-form input[type=password], .app-form textarea, .app-form select {
            width: 100%; background: var(--bg-input); border: 1px solid var(--bg-input); color: var(--text-bright); padding: 8px 10px; font-family: var(--font-ui); font-size: 13px; transition: 0.2s; outline: none; margin-bottom: 15px;
        }
        .app-form input:focus, .app-form textarea:focus { border-color: var(--accent); }
        .app-form textarea.code-editor { font-family: var(--font-code); line-height: 1.5; font-size: 13px; background: #181818; }
        
        .row { display: flex; gap: 20px; }
        .col { flex: 1; }

        /* BUTTONS */
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 6px 14px; border: 1px solid transparent; font-size: 12px; font-weight: 600; cursor: pointer; transition: calc(0.2s * var(--anim-speed)); white-space: nowrap; height: 30px; line-height: 1; border-radius: 4px; }
        body.ui-compact .btn { height: 26px; font-size: 11px; padding: 4px 10px; }
        body.ui-no-anim * { transition: none !important; animation: none !important; }
        body.ui-noise::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='<?php echo $noise_opacity/100; ?>'/%3E%3C/svg%3E");
            mix-blend-mode: soft-light;
            z-index: 1;
        }
        body.ui-noise .sidebar,
        body.ui-noise .main-view { position: relative; z-index: 2; }
        body.ui-blur .topbar { backdrop-filter: blur(10px); background: color-mix(in srgb, #1b1b1b 85%, transparent); }
        .btn-primary { background: var(--accent); color: white; <?php if($accent_glow) echo "box-shadow: 0 10px 32px color-mix(in srgb, var(--accent) 40%, transparent);"; ?> }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-secondary { background: #3c3c3c; color: white; border-color: #4a4a4a; }
        .btn-secondary:hover { background: #4a4a4a; }
        .btn-danger { background: transparent; color: var(--danger); border: 1px solid var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }
        .btn-sm { padding: 4px 10px; font-size: 11px; height: 24px; }

        /* TABLES */
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); color: var(--text-dim); font-weight: normal; font-size: 12px; }
        td { padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }

        /* EXTRAS */
        .flash { padding: 10px 15px; margin-bottom: 20px; font-size: 12px; border-left: 4px solid; display: flex; justify-content: space-between; }
        .flash.success { background: rgba(137, 209, 133, 0.1); border-color: var(--success); color: var(--success); }
        .flash.error { background: rgba(244, 135, 113, 0.1); border-color: var(--danger); color: var(--danger); }
        
        details { background: rgba(0,0,0,0.2); border: 1px solid var(--border); margin-bottom: 15px; }
        details summary { padding: 10px; cursor: pointer; font-weight: 600; font-size: 12px; color: var(--text-dim); user-select: none; }
        details[open] summary { border-bottom: 1px solid var(--border); color: var(--text-bright); }
        details > div { padding: 15px; }

        .status-badge { background: #333; padding: 2px 6px; font-size: 11px; border-radius: 3px; color: #888; font-family: var(--font-code); }

        /* MOBILE */
        @media (max-width: 768px) {
            body { flex-direction: column; height: auto; overflow: auto; }
            .sidebar { width: 100%; height: auto; border-right: none; border-bottom: 1px solid var(--border); }
            .main-view { height: auto; overflow: visible; }
            .nav-section { display: flex; overflow-x: auto; padding: 0; }
            .nav-label { display: none; }
            .nav-item { padding: 12px; white-space: nowrap; border-left: none; border-bottom: 3px solid transparent; }
        .nav-item.active { background: transparent; border-bottom-color: var(--accent); }
        .topbar { position: sticky; top: 0; z-index: 99; flex-wrap: wrap; gap: 8px; }
        .topbar-actions { width: 100%; }
        .quick-jump { width: 100%; }
        .content-scroll { padding: 14px 14px 18px; }
            .kpi-grid, .dash-columns, .dash-grid-3 { grid-template-columns: 1fr !important; }
            .row { flex-direction: column; gap: 12px; }
            .card { margin-bottom: 14px; }
            .btn { height: 32px; font-size: 12px; }
            .pro-settings { flex-direction: column; height: auto; }
            .settings-sidebar { width: 100%; flex-direction: row; overflow-x: auto; }
            .settings-content { width: 100%; }
        }
        @media (max-width: 540px) {
            .cloud-toolbar, .mods-toolbar, .topbar-actions { flex-direction: column; align-items: stretch; }
            .btn, .quick-jump, .box-input, .cloud-search { width: 100%; }
            .sidebar { position: relative; }
            .brand { text-align: center; }
            .nav-item { flex: 1; text-align: center; justify-content: center; }
            #burgerBtn { display:inline-flex; }
            .sidebar { position: fixed; z-index: 100; left: -110%; top:0; bottom:0; transition: 0.25s; }
            .sidebar.open { left:0; }
            .main-view { margin-left:0 !important; }
        }
    </style>
</head>
<body class="<?php echo implode(' ', $body_classes); ?>">

<nav class="sidebar" id="sidebar">
    <div class="brand"><?php echo h($custom_logo ?: ($TITLE . ' v' . ADMIN_VERSION)); ?></div>
    
    <div class="nav-section">
        <div class="nav-label">Основное</div>
        <?php if ($nav_show_panel && $can_panel): ?>
            <a href="<?php echo ADMIN_FILE; ?>?page=panel" class="nav-item <?php echo $page=='panel'?'active':''; ?>"><?php echo h($nav_label_panel); ?></a>
        <?php endif; ?>
        <?php if ($nav_show_modules && $can_modules): ?>
            <a href="<?php echo ADMIN_FILE; ?>?page=modules" class="nav-item <?php echo $page=='modules'?'active':''; ?>"><?php echo h($nav_label_modules); ?></a>
        <?php endif; ?>
        <?php if ($nav_show_js && $can_js): ?>
            <a href="<?php echo ADMIN_FILE; ?>?page=js" class="nav-item <?php echo $page=='js'?'active':''; ?>"><?php echo h($nav_label_js); ?></a>
        <?php endif; ?>
        <?php if ($nav_show_settings && $can_settings): ?>
            <a href="<?php echo ADMIN_FILE; ?>?page=settings" class="nav-item <?php echo $page=='settings'?'active':''; ?>"><?php echo h($nav_label_settings); ?></a>
        <?php endif; ?>
        <?php if (!empty($nav_custom_links)): ?>
            <div class="nav-label" style="margin-top:8px;">Быстрые ссылки</div>
            <?php foreach($nav_custom_links as $cl): 
                $href = h($cl['url'] ?? '#');
                $title = h($cl['title'] ?? 'Link');
                $target = h($cl['target'] ?? '_blank');
            ?>
                <a href="<?php echo $href; ?>" target="<?php echo $target; ?>" class="nav-item"><?php echo $title; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="nav-section" id="pinnedSection" style="display:none;">
        <div class="nav-label">Избранное</div>
        <div id="pinnedList"></div>
    </div>

    <?php if ($can_modules): ?>
    <div class="nav-section" style="flex: 1; overflow-y: auto;">
        <div style="display:flex; align-items:center; justify-content:space-between; padding: 0 14px 4px;">
            <div class="nav-label" style="margin:0;">Активные модули</div>
            <button type="button" class="nav-toggle-btn" id="modulesToggle"><?php echo $menu_modules_collapsed ? '▶' : '▼'; ?></button>
        </div>
            <div class="nav-search">
                <input type="text" id="moduleSearch" placeholder="Поиск модуля...">
            </div>
            <div class="nav-count" id="moduleCount"></div>
            <div id="modulesWrap" class="<?php echo $menu_modules_collapsed ? 'collapsed' : ''; ?>">
            <?php foreach($nav_mods as $mid => $m): ?>
                <?php if (!can_module($mid)) continue; ?>
                <a href="<?php echo ADMIN_FILE; ?>?module=<?php echo $mid; ?>" class="nav-item <?php echo ($active_mod == $mid)?'active':''; ?>" data-module-name="<?php echo strtolower(h($m['name'])); ?>" data-module-id="<?php echo h($mid); ?>">
                    <?php echo h($m['name']); ?>
                    <span class="nav-star" data-star="<?php echo h($mid); ?>" title="В избранное">★</span>
                </a>
            <?php endforeach; ?>
            <?php if(empty($nav_mods)): ?>
                <div style="padding: 10px 20px; color: #555; font-size: 12px; font-style: italic;">Нет модулей</div>
            <?php endif; ?>
        </div>
        <div class="nav-mini" id="recentLabel" style="display:none;">Недавние</div>
        <div id="recentList" <?php echo $show_recent ? '' : 'style="display:none;"'; ?>></div>
    </div>
    <?php endif; ?>

    <a href="<?php echo ADMIN_FILE; ?>?logout=1" class="nav-item logout">Выход</a>
</nav>

<main class="main-view">
    <div class="topbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <button class="btn btn-secondary btn-sm" onclick="toggleSidebar()" aria-label="Меню" style="display:none;" id="burgerBtn">☰</button>
            <div>
                <div class="topbar-title"><?php echo h($page_title); ?></div>
                <div class="topbar-sub"><?php echo h($page_subtitle); ?></div>
            </div>
        </div>
        <div class="topbar-actions">
            <div class="pill" title="Текущий пользователь"><?php echo h($auth_name); ?> • <?php echo h($role_label); ?></div>
            <input class="quick-jump" id="quickJump" list="quickJumpList" placeholder="Быстрый переход...">
            <datalist id="quickJumpList">
                <?php if ($can_panel): ?><option value="Панель"></option><?php endif; ?>
                <?php if ($can_modules): ?><option value="Модули"></option><?php endif; ?>
                <?php if ($can_js): ?><option value="JS Плагины"></option><?php endif; ?>
                <?php if ($can_settings): ?><option value="Настройки"></option><?php endif; ?>
                <?php foreach($mod_settings as $mid => $m): ?>
                    <?php if (!can_module($mid)) continue; ?>
                    <option value="<?php echo h($m['name']); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <a href="https://codecraftpmr.ru" target="_blank" class="btn btn-secondary">Сайт</a>
            <?php if (can('settings_save')): ?>
            <form method="POST" class="theme-quick">
                <input type="hidden" name="action" value="quick_theme">
                <?php 
                    $quick_themes = [
                        'dark' => 'Dark',
                        'graphite' => 'Graphite',
                        'midnight' => 'Midnight',
                        'contrast' => 'Contrast',
                        'dracula' => 'Dracula',
                        'monokai' => 'Monokai',
                        'obsidian' => 'Obsidian',
                        'ocean' => 'Ocean',
                        'cyber' => 'Cyber'
                    ];
                    foreach ($quick_themes as $tid => $label): 
                ?>
                    <button type="submit" name="theme" value="<?php echo $tid; ?>" class="theme-chip <?php echo $theme===$tid?'active':''; ?>"><?php echo $label; ?></button>
                <?php endforeach; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-scroll">
        <?php 
        // 1. FLASH MESSAGES WITH ANIMATION
        if(isset($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            echo "
            <div class='flash {$f['type']}' style='animation: fadeIn 0.3s ease-out; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display:flex; align-items:center; gap:10px;'>
                " . ($f['type'] === 'success' ? '<span style="font-size:16px">✓</span>' : '<span style="font-size:16px">⚠</span>') . "
                <span>{$f['text']}</span>
            </div>";
            unset($_SESSION['flash']);
        }

        define('IS_ADMIN', true);

        // 2. ROUTING LOGIC
        if ($active_mod) {
            // === MODULE EDITOR ===
            $info = $mod_settings[$active_mod] ?? null;
            if ($info && file_exists(MODULES_DIR . $info['file'])) {
                include MODULES_DIR . $info['file'];
            } else {
                echo "<div class='flash error'>Module file not found: <b>".h($active_mod)."</b></div>";
            }
        } else {
            // === DASHBOARD PAGES ===
            switch($page) {
                case 'panel': 
                    $hour = (int)date('H');
                    $greeting = ($hour < 12) ? 'Доброе утро' : (($hour < 18) ? 'Добрый день' : 'Добрый вечер');
                    $today = date('d.m.Y');
                    $now = date('H:i');

                    $leads_file = DATA_DIR . 'leads.json';
                    $leads = [];
                    if (file_exists($leads_file)) {
                        $decoded = json_decode(file_get_contents($leads_file), true);
                        if (is_array($decoded)) $leads = $decoded;
                    }
                    $leads_count = count($leads);
                    $last_lead = $leads[0] ?? null;
                    $recent_leads = array_slice($leads, 0, 5);

                    $data_files = glob(DATA_DIR . '*.json') ?: [];
                    usort($data_files, function($a, $b) { return filemtime($b) <=> filemtime($a); });
                    $recent_data_files = array_slice($data_files, 0, 5);

                    $dirs_check = [
                        'Modules' => MODULES_DIR,
                        'Data' => DATA_DIR,
                        'Uploads' => UPLOADS_DIR
                    ];
                    $warnings = [];
                    foreach ($dirs_check as $name => $path) {
                        if (!is_writable($path)) $warnings[] = "Нет прав записи: $name";
                    }
                    if ($leads_count === 0) $warnings[] = "Пока нет заявок из формы";

                    $disk_total = function_exists('disk_total_space') ? @disk_total_space(".") : 0;
                    $disk_free = function_exists('disk_free_space') ? @disk_free_space(".") : 0;
                    $disk_total = is_numeric($disk_total) ? (float)$disk_total : 0;
                    $disk_free = is_numeric($disk_free) ? (float)$disk_free : 0;
                    $disk_used = $disk_total ? ($disk_total - $disk_free) : 0;
                    $disk_pct = $disk_total ? (int)round(($disk_used / $disk_total) * 100) : 0;
                    $disk_pct = max(0, min(100, $disk_pct));

                    $mods_missing = 0;
                    foreach ($mod_settings as $mid => $m) {
                        if (!file_exists(MODULES_DIR . $mid . '.php')) $mods_missing++;
                    }
                ?>
                    <style>
                        .dash-hero { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 18px 20px; border: 1px solid var(--border); background: linear-gradient(135deg, #232323, #1a1a1a); }
                        .dash-title { font-size: 22px; font-weight: 600; color: var(--text-bright); }
                        .dash-sub { font-size: 12px; color: var(--text-dim); margin-top: 4px; }
                        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }

                        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin: 20px 0; }
                        .kpi-card { background: var(--bg-sidebar); border: 1px solid var(--border); padding: 16px; border-radius: 6px; }
                        .kpi-label { font-size: 11px; text-transform: uppercase; color: var(--text-dim); letter-spacing: 0.5px; }
                        .kpi-value { font-size: 22px; color: var(--text-bright); margin-top: 6px; }
                        .kpi-sub { font-size: 11px; color: var(--text-dim); margin-top: 4px; }

                        .dash-columns { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
                        .dash-card { background: var(--bg-sidebar); border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
                        .dash-card-head { padding: 12px 14px; border-bottom: 1px solid var(--border); font-size: 12px; text-transform: uppercase; color: var(--text-dim); letter-spacing: 0.5px; }
                        .dash-card-body { padding: 14px; }
                        .dash-list { display: flex; flex-direction: column; gap: 12px; }
                        .dash-item { padding: 10px; border: 1px solid rgba(255,255,255,0.05); background: #222; border-radius: 6px; }
                        .dash-item-title { font-size: 13px; color: var(--text-bright); }
                        .dash-item-meta { font-size: 11px; color: var(--text-dim); margin-top: 4px; display: flex; gap: 10px; flex-wrap: wrap; }
                        .chip { font-size: 10px; padding: 2px 6px; border-radius: 10px; background: #333; color: #aaa; font-family: var(--font-code); }
                        .empty-state { font-size: 12px; color: var(--text-dim); padding: 10px; border: 1px dashed var(--border); border-radius: 6px; text-align: center; }

                        .sys-table { width: 100%; border-collapse: collapse; }
                        .sys-table td { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 12px; color: var(--text-main); }
                        .sys-table tr:last-child td { border-bottom: none; }
                        .badge { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; background: #333; color: #aaa; font-family: var(--font-code); }
                        .badge.ok { background: rgba(137, 209, 133, 0.2); color: var(--success); }
                        .badge.warn { background: rgba(244, 135, 113, 0.2); color: var(--danger); }
                        .path-code { font-family: var(--font-code); color: var(--text-dim); font-size: 11px; word-break: break-all; }
                        .dash-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin: 20px 0; }
                        .dash-mini { background: var(--bg-sidebar); border: 1px solid var(--border); border-radius: 6px; padding: 14px; }
                        .dash-mini h4 { margin: 0 0 6px; font-size: 13px; color: var(--text-bright); }
                        .dash-mini p { margin: 0; font-size: 12px; color: var(--text-dim); }
                        .progress { height: 8px; background: #1b1b1b; border: 1px solid var(--border); border-radius: 999px; overflow: hidden; margin-top: 10px; }
                        .progress > span { display: block; height: 100%; background: var(--accent); width: 0%; }
                        .pill { display:inline-flex; align-items:center; gap:6px; background:#202020; border:1px solid var(--border); padding:4px 8px; border-radius:999px; font-size:11px; color:var(--text-dim); }
                        .recent-mods { display:flex; flex-direction:column; gap:10px; }
                        .recent-mods a { display:flex; justify-content:space-between; gap:10px; padding:8px 10px; background:#222; border:1px solid rgba(255,255,255,0.05); border-radius:6px; }
                        .recent-mods code { font-size:10px; }

                        @media (max-width: 900px) {
                            .dash-columns { grid-template-columns: 1fr; }
                            .hero-actions { width: 100%; }
                        }
                    </style>

                    <div class="dash-hero">
                        <div>
                            <div class="dash-title"><?php echo $greeting; ?>!</div>
                            <div class="dash-sub">Сегодня <?php echo $today; ?> • <?php echo $now; ?> • Панель управления</div>
                        </div>
                        <div class="hero-actions">
                            <a href="https://codecraftpmr.ru" target="_blank" class="btn btn-secondary" style="gap:8px;">
                                Открыть сайт
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            </a>
                            <?php if (isset($mod_settings['contacts-leads'])): ?>
                                <a href="<?php echo ADMIN_FILE; ?>?module=contacts-leads" class="btn btn-primary">Заявки</a>
                            <?php endif; ?>
                            <a href="<?php echo ADMIN_FILE; ?>?page=modules" class="btn btn-secondary">Модули</a>
                            <a href="<?php echo ADMIN_FILE; ?>?page=settings" class="btn btn-secondary">Настройки</a>
                        </div>
                    </div>

                    <?php if ($show_kpi): ?>
                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div class="kpi-label">Новые заявки</div>
                            <div class="kpi-value"><?php echo $leads_count; ?></div>
                            <div class="kpi-sub">
                                <?php echo $last_lead ? 'Последняя: ' . h($last_lead['name'] ?? '—') . ' • ' . h($last_lead['date'] ?? '') : 'Пока нет заявок'; ?>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Активных модулей</div>
                            <div class="kpi-value"><?php echo count($mod_settings); ?></div>
                            <div class="kpi-sub">В меню слева</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">JS плагины</div>
                            <div class="kpi-value"><?php echo count($PLUGINS); ?></div>
                            <div class="kpi-sub">Подключено</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Свободно на диске</div>
                            <div class="kpi-value"><?php echo $disk_free ? format_bytes($disk_free) : '—'; ?></div>
                            <div class="kpi-sub">Всего: <?php echo $disk_total ? format_bytes($disk_total) : '—'; ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">PHP</div>
                            <div class="kpi-value"><?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; ?></div>
                            <div class="kpi-sub">Память: <?php echo ini_get('memory_limit'); ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">Статус</div>
                            <div class="kpi-value"><?php echo empty($warnings) ? 'OK' : 'Внимание'; ?></div>
                            <div class="kpi-sub"><?php echo empty($warnings) ? 'Система стабильна' : count($warnings) . ' предупрежд.'; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="dash-grid-3">
                        <div class="dash-mini">
                            <h4>Диск</h4>
                            <p>Использовано: <?php echo format_bytes($disk_used); ?> из <?php echo format_bytes($disk_total); ?></p>
                            <div class="progress"><span style="width: <?php echo $disk_pct; ?>%;"></span></div>
                            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                <span class="pill">Свободно: <?php echo format_bytes($disk_free); ?></span>
                                <span class="pill">Нагрузка: <?php echo $disk_pct; ?>%</span>
                            </div>
                        </div>
                        <div class="dash-mini">
                            <h4>Модули</h4>
                            <p>Всего: <?php echo count($mod_settings); ?> • Проблемных: <?php echo $mods_missing; ?></p>
                            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                <span class="pill">JS плагины: <?php echo count($PLUGINS); ?></span>
                                <span class="pill">Данные: <?php echo count($data_files); ?></span>
                            </div>
                        </div>
                        <div class="dash-mini">
                            <h4>Система</h4>
                            <p>PHP <?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; ?> • Memory: <?php echo ini_get('memory_limit'); ?></p>
                            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                <span class="pill">Upload: <?php echo ini_get('upload_max_filesize'); ?></span>
                                <span class="pill">Post: <?php echo ini_get('post_max_size'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="dash-columns">
                        <div class="dash-card">
                            <div class="dash-card-head">Последние заявки</div>
                            <div class="dash-card-body">
                                <?php if (!empty($recent_leads)): ?>
                                    <div class="dash-list">
                                        <?php foreach ($recent_leads as $lead): ?>
                                            <div class="dash-item">
                                                <div class="dash-item-title"><?php echo h($lead['name'] ?? 'Без имени'); ?></div>
                                                <div class="dash-item-meta">
                                                    <span class="chip"><?php echo h($lead['date'] ?? ''); ?></span>
                                                    <span><?php echo h($lead['phone'] ?? ''); ?></span>
                                                    <span><?php echo h($lead['service'] ?? ''); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">Заявок пока нет</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="dash-card">
                            <div class="dash-card-head">Быстрые действия</div>
                            <div class="dash-card-body">
                                <div style="display:flex; flex-direction:column; gap:10px;">
                                    <a href="<?php echo ADMIN_FILE; ?>?page=modules" class="btn btn-secondary">Управление модулями</a>
                                    <?php if (isset($mod_settings['blog'])): ?>
                                        <a href="<?php echo ADMIN_FILE; ?>?module=blog" class="btn btn-secondary">Редактор блога</a>
                                    <?php endif; ?>
                                    <?php if (isset($mod_settings['seo'])): ?>
                                        <a href="<?php echo ADMIN_FILE; ?>?module=seo" class="btn btn-secondary">SEO настройки</a>
                                    <?php endif; ?>
                                    <?php if (isset($mod_settings['contacts-leads'])): ?>
                                        <a href="<?php echo ADMIN_FILE; ?>?module=contacts-leads" class="btn btn-primary">Перейти к заявкам</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dash-columns" style="margin-top:20px;">
                        <div class="dash-card">
                            <div class="dash-card-head">Недавние модули</div>
                            <div class="dash-card-body">
                                <div class="recent-mods" id="recentModsPanel">
                                    <div class="empty-state">Нет недавних модулей</div>
                                </div>
                            </div>
                        </div>
                        <div class="dash-card">
                            <div class="dash-card-head">Системный статус</div>
                            <div class="dash-card-body">
                                <table class="sys-table">
                                    <?php 
                                    echo "<tr><td><strong style='color:var(--text-bright)'>Корень проекта</strong><br><span class='path-code'>".h(SITE_ROOT)."</span></td><td align='right'><span class='badge ok'>REALPATH</span></td></tr>";
                                    foreach($dirs_check as $name => $path) {
                                        $writable = is_writable($path);
                                        $status = $writable ? '<span class="badge ok">WRITABLE</span>' : '<span class="badge warn">READ ONLY</span>';
                                        $path_display = str_replace(SITE_ROOT, '.../', $path);
                                        echo "<tr>
                                            <td>$name<br><span class='path-code'>$path_display</span></td>
                                            <td align='right'>$status</td>
                                        </tr>";
                                    }
                                    echo "<tr><td>Сервер</td><td align='right' style='color:var(--text-bright)'>".h($_SERVER['SERVER_SOFTWARE'])."</td></tr>";
                                    echo "<tr><td>Макс. загрузка</td><td align='right' style='color:var(--text-bright)'>".ini_get('upload_max_filesize')."</td></tr>";
                                    ?>
                                </table>
                                <?php if (!empty($warnings)): ?>
                                    <div style="margin-top:12px; display:flex; flex-direction:column; gap:6px;">
                                        <?php foreach ($warnings as $w): ?>
                                            <div class="dash-item" style="border-color: rgba(244,135,113,0.3);"><?php echo h($w); ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="dash-card">
                            <div class="dash-card-head">Последние изменения данных</div>
                            <div class="dash-card-body">
                                <?php if (!empty($recent_data_files)): ?>
                                    <div class="dash-list">
                                        <?php foreach ($recent_data_files as $file): ?>
                                            <div class="dash-item">
                                                <div class="dash-item-title"><?php echo h(basename($file)); ?></div>
                                                <div class="dash-item-meta">
                                                    <span class="chip"><?php echo date('d.m.Y H:i', filemtime($file)); ?></span>
                                                    <span><?php echo format_bytes(filesize($file)); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">Нет данных</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <script>
                        (function () {
                            const container = document.getElementById('recentModsPanel');
                            if (!container) return;
                            let recent = [];
                            try { recent = JSON.parse(localStorage.getItem('cc_admin_recent') || '[]'); } catch(e) { recent = []; }
                            if (!recent.length) return;
                            const links = recent.slice(0, 6).map(id => {
                                return `<a href="<?php echo ADMIN_FILE; ?>?module=${id}">
                                    <span>${id}</span>
                                    <code>#${id}</code>
                                </a>`;
                            }).join('');
                            container.innerHTML = links;
                        })();
                    </script>

                <?php break;
            // End case 'panel', continue other cases...

case 'modules': 
                    // === LOGIC: REORDER ===
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_modules') {
                        require_perm_or_redirect('modules_reorder', 'modules');
                        $order_ids = json_decode($_POST['order_json'], true);
                        if (is_array($order_ids)) {
                            $new_settings = [];
                            // Rebuild array in new order, keeping only existing keys
                            foreach ($order_ids as $id) {
                                if (isset($mod_settings[$id])) {
                                    $new_settings[$id] = $mod_settings[$id];
                                }
                            }
                            // Append any missing ones (safety fallback)
                            foreach ($mod_settings as $id => $val) {
                                if (!isset($new_settings[$id])) {
                                    $new_settings[$id] = $val;
                                }
                            }
                            save_settings_file($new_settings);
                            redirect_with_message('success', 'Порядок меню обновлён', ['page' => 'modules']);
                        }
                    }

                    // === VIEW 1: CODE EDITOR (IDE MODE) ===
                    if (isset($_GET['edit_module'])) {
                        require_module_action_or_redirect($_GET['edit_module'] ?? '', 'modules_edit_code', 'modules');
                        $eid = $_GET['edit_module'];
                        require_module_access($eid, 'modules');
                        $file_path = MODULES_DIR . $eid . '.php';
                        $code = file_exists($file_path) ? file_get_contents($file_path) : "";
                        $lines = substr_count($code, "\n") + 1;
                        ?>
                        <style>
                            .ide-container { display: flex; height: calc(100vh - 160px); border: 1px solid var(--border); background: #1e1e1e; position: relative; }
                            .line-numbers { 
                                background: #252526; color: #5a5a5a; text-align: right; 
                                padding: 15px 10px 15px 0; font-family: var(--font-code); 
                                font-size: 13px; line-height: 21px; width: 45px; user-select: none;
                                border-right: 1px solid #333; overflow: hidden;
                            }
                            .code-area-wrap { flex: 1; position: relative; }
                            .ide-textarea {
                                width: 100%; height: 100%; border: none; background: transparent; 
                                color: #d4d4d4; padding: 15px; font-family: var(--font-code); 
                                font-size: 13px; line-height: 21px; resize: none; outline: none;
                                white-space: pre; overflow: auto;
                            }
                            .shortcut-badge { background: #333; padding: 2px 5px; border-radius: 4px; font-size: 10px; color: #aaa; margin-left: 10px; border: 1px solid #444; }
                        </style>

                        <div class="panel-header">
                            <div>
                                <h1 style="display:flex; align-items:center; gap: 10px;">
                                    <span style="opacity:0.5">DEV :: </span> 
                                    <span style="font-family:var(--font-code); color:var(--accent);"><?php echo h($eid); ?>.php</span>
                                </h1>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <a href="?page=modules" class="btn btn-secondary">Закрыть</a>
                                <button type="button" id="saveBtn" onclick="submitCode()" class="btn btn-primary">Сохранить <span class="shortcut-badge">CTRL+S</span></button>
                            </div>
                        </div>

                        <form id="codeForm" method="POST">
                            <input type="hidden" name="action" value="edit_code_mod">
                            <input type="hidden" name="mid" value="<?php echo $eid; ?>">
                            
                            <div class="ide-container">
                                <div class="line-numbers" id="lineNums">1</div>
                                <div class="code-area-wrap">
                                    <textarea name="code" id="codeEditor" class="ide-textarea" spellcheck="false" 
                                    oninput="updateLines()" onscroll="syncScroll()"><?php echo h($code); ?></textarea>
                                </div>
                            </div>
                        </form>

                        <script>
                            const editor = document.getElementById('codeEditor');
                            const lineBox = document.getElementById('lineNums');
                            
                            function updateLines() {
                                const lines = editor.value.split('\n').length;
                                lineBox.innerHTML = Array(lines).fill(0).map((_, i) => i + 1).join('<br>');
                            }
                            function syncScroll() { lineBox.scrollTop = editor.scrollTop; }
                            function submitCode() { document.getElementById('codeForm').submit(); }

                            editor.addEventListener('keydown', function(e) {
                                if (e.key == 'Tab') {
                                    e.preventDefault();
                                    const start = this.selectionStart;
                                    const end = this.selectionEnd;
                                    this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
                                    this.selectionStart = this.selectionEnd = start + 4;
                                }
                                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                                    e.preventDefault();
                                    submitCode();
                                }
                            });
                            // Init
                            updateLines();
                        </script>

                    <?php 
                    // === VIEW 2: MODULES MANAGER LIST ===
                    } else {
                        $mod_total = count($mod_settings);
                        $mod_missing = 0;
                        $mod_has_data = 0;
                        $mod_has_uploads = 0;
                        foreach ($mod_settings as $id => $m) {
                            $mfile = MODULES_DIR . $id . '.php';
                            $jfile = DATA_DIR . $id . '.json';
                            $udir = UPLOADS_DIR . $id;
                            if (!file_exists($mfile)) $mod_missing++;
                            if (file_exists($jfile)) $mod_has_data++;
                            if (is_dir($udir)) $mod_has_uploads++;
                        }
                    ?>
                        <style>
                            .search-box {
                                position: relative; width: 300px;
                            }
                            .search-box input {
                                padding-left: 35px; width: 100%; background: #252526; border-radius: 4px; border: 1px solid #3e3e42;
                                margin-bottom: 0;
                            }
                            .search-icon {
                                position: absolute; left: 10px; top: 50%; transform: translateY(-50%); opacity: 0.5; width: 14px;
                            }
                            /* Drag and Drop List */
                            .mod-list { display: flex; flex-direction: column; gap: 8px; margin-top: 15px; }
                            .mod-item { 
                                display: flex; align-items: center; background: #252526; border: 1px solid var(--border); 
                                padding: 12px 15px; border-radius: 4px; transition: background 0.1s; 
                            }
                            .mod-item:hover { background: #2a2d2e; border-color: #555; }
                            .mod-item.sort-ghost { opacity: 0.4; border: 1px dashed var(--accent); background: #222; }
                            
                            .drag-handle { 
                                cursor: grab; padding: 5px; opacity: 0.3; margin-right: 15px; display: flex; align-items: center; 
                            }
                            .drag-handle:hover { opacity: 1; color: var(--text-bright); }
                            
                            .mod-icon {
                                width: 32px; height: 32px; background: #333; display: flex; align-items: center; justify-content: center;
                                border-radius: 4px; color: var(--accent); margin-right: 15px; font-size: 14px; font-weight: bold;
                            }
                            
                            .mod-info { flex: 1; }
                            .mod-title { font-weight: 600; font-size: 14px; color: var(--text-bright); display: block; margin-bottom: 3px; }
                            .mod-meta { font-size: 11px; color: var(--text-dim); display: flex; gap: 15px; align-items: center; }
                            .mod-meta code { font-size: 11px; color: #888; background: rgba(0,0,0,0.2); border:none; }
                            
                            .mod-actions { display: flex; align-items: center; gap: 8px; }
                            .icon-btn { 
                                width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; 
                                border: 1px solid transparent; border-radius: 3px; color: var(--text-dim); opacity: 0.8; 
                            }
                            .icon-btn:hover { background: #333; color: var(--text-bright); opacity: 1; border-color: #444; }
                            
                            .copy-confirm { position: fixed; bottom: 20px; right: 20px; background: var(--accent); color: white; padding: 10px 20px; border-radius: 4px; display: none; z-index: 100; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }

                            .order-alert { background: rgba(0,122,204, 0.2); border: 1px solid var(--accent); padding: 10px 15px; border-radius: 4px; display: none; align-items: center; justify-content: space-between; margin-bottom: 15px; animation: slideDown 0.3s; }
                            @keyframes slideDown { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

                            .mods-toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin: 10px 0 15px; }
                            .mods-toolbar .box-input { margin:0; height:32px; }
                            .mods-toolbar .btn { height:32px; }
                            .mods-stat { display:flex; gap:12px; flex-wrap:wrap; margin: 10px 0 15px; }
                            .mods-stat .stat { background: var(--bg-panel-2); border: 1px solid var(--border); padding: 10px 12px; border-radius: 6px; font-size: 12px; }
                            .mods-stat .stat b { color: var(--text-bright); }
                            .badge-mini { font-size: 10px; padding: 2px 6px; border-radius: 8px; border: 1px solid #444; color: var(--text-dim); }
                            .badge-mini.ok { color: var(--success); border-color: rgba(137,209,133,0.4); }
                            .badge-mini.warn { color: var(--danger); border-color: rgba(244,135,113,0.4); }
                            .mod-meta { flex-wrap: wrap; }
                            .mod-meta .badge-mini { margin-right: 6px; }
                            .mod-check { width: 14px; height: 14px; accent-color: var(--accent); }
                            .mod-item.missing { border-color: rgba(244,135,113,0.5); }
                            .mod-edit { margin-top: 10px; background: #1e1e1e; border: 1px dashed var(--border); border-radius: 6px; padding: 12px; }
                            .mod-edit summary { display: none; }
                            .mod-edit .row { gap: 12px; }
                            .mod-edit .box-input { margin: 0; }
                            .mod-access { margin-top: 10px; background: #1f1f1f; border: 1px solid #303030; border-radius: 6px; padding: 10px; }
                            .mod-access-title { font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; }
                            .mod-access-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 8px 10px; }
                            .mod-access-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; background: #171717; border: 1px solid #2a2a2a; border-radius: 6px; padding: 7px 9px; }
                            .mod-access-name { font-size: 12px; color: var(--text-main); }
                            .mod-access-name code { font-size: 10px; color: #9ca3af; background: rgba(255,255,255,0.04); }
                            .mod-access-actions { margin-top: 8px; display: flex; justify-content: flex-end; }
                            .mod-access-empty { font-size: 12px; color: var(--text-dim); }
                            .mod-access-status { display:flex; gap:8px; flex-wrap:wrap; margin-bottom: 8px; }
                            .mod-access-inline { display:flex; gap:8px; align-items:center; }
                            .mod-status-live { font-size: 11px; color: var(--text-dim); min-height: 14px; margin-top: 6px; }
                            .mod-status-live.ok { color: var(--success); }
                            .mod-status-live.err { color: var(--danger); }
                            .mod-switch { position: relative; display: inline-block; width: 36px; height: 20px; flex-shrink: 0; }
                            .mod-switch input { opacity: 0; width: 0; height: 0; }
                            .mod-switch-slider { position: absolute; inset: 0; background: #3e3e42; border-radius: 20px; transition: .18s; cursor: pointer; }
                            .mod-switch-slider:before { content: ""; position: absolute; height: 14px; width: 14px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .18s; }
                            .mod-switch input:checked + .mod-switch-slider { background: var(--accent); }
                            .mod-switch input:checked + .mod-switch-slider:before { transform: translateX(16px); }
                        </style>

                        <!-- Header with Search -->
                        <div class="panel-header" style="padding-bottom: 15px;">
                            <h1 style="margin:0;">Модули</h1>
                            <div class="search-box">
                                <svg class="search-icon" fill="currentColor" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
                                <input type="text" id="modSearch" placeholder="Поиск модулей..." autocomplete="off">
                            </div>
                        </div>

                        <div class="mods-stat">
                            <div class="stat">Всего: <b><?php echo $mod_total; ?></b></div>
                            <div class="stat">С данными: <b><?php echo $mod_has_data; ?></b></div>
                            <div class="stat">С загрузками: <b><?php echo $mod_has_uploads; ?></b></div>
                            <div class="stat">Проблемные: <b><?php echo $mod_missing; ?></b></div>
                        </div>

                        <div class="mods-toolbar">
                            <select id="modSort" class="box-input" style="width:180px;">
                                <option value="mtime" selected>Сортировка: Сначала новые</option>
                                <option value="name">Сортировка: Название</option>
                                <option value="id">Сортировка: ID</option>
                                <option value="size">Сортировка: Размер</option>
                            </select>
                            <select id="modFilter" class="box-input" style="width:200px;">
                                <option value="all">Фильтр: Все</option>
                                <option value="has-data">Есть данные</option>
                                <option value="has-uploads">Есть загрузки</option>
                                <option value="missing">Нет файла</option>
                            </select>
                            <button class="btn btn-secondary" type="button" id="resetModView">Сброс</button>
                            <div style="margin-left:auto; font-size:12px; color:var(--text-dim);" id="modCountInfo"></div>
                        </div>

                        <div class="mods-toolbar" style="background: var(--bg-panel-2); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px;">
                                <input type="checkbox" id="modSelectAll">
                                Выбрать все
                            </label>
                            <button class="btn btn-secondary btn-sm" type="button" id="modClearSel">Очистить</button>
                            <button class="btn btn-secondary btn-sm" type="button" id="modCopyIds">Скопировать ID</button>
                            <button class="btn btn-secondary btn-sm" type="button" id="modCopyLinks">Скопировать ссылки</button>
                            <button class="btn btn-secondary btn-sm" type="button" id="modCopyPaths">Скопировать пути</button>
                            <button class="btn btn-secondary btn-sm" type="button" id="modExportCsv">Экспорт CSV</button>
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; margin-left:auto;">
                                <input type="checkbox" id="modOnlySelected">
                                Только выбранные
                            </label>
                            <div style="font-size:12px; color:var(--text-dim);" id="modSelInfo">Выбрано: 0</div>
                        </div>
                        
                        <!-- Create New Toggle -->
                        <?php if (can('modules_add')): ?>
                        <details style="border-radius: 4px; overflow: hidden; background: #252526; border-color: #3e3e42;">
                            <summary style="background: rgba(255,255,255,0.05);">+ Создать модуль</summary>
                            <form method="POST" class="app-form" style="margin-bottom:0;" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="add_module">
                                <div class="row" style="align-items: flex-end;">
                                    <div class="col" style="flex:1">
                                        <label>Название в меню</label>
                                        <input type="text" id="newName" name="mname" placeholder="Мой раздел" required style="margin:0">
                                    </div>
                                    <div class="col" style="flex:1">
                                        <label>Уникальный ID</label>
                                        <input type="text" id="newID" name="mid" placeholder="my-section" required style="background:#1e1e1e; margin:0">
                                    </div>
                                    <div class="col" style="flex:0;">
                                        <button class="btn btn-primary">Создать</button>
                                    </div>
                                </div>
                                <div class="row" style="align-items:flex-end; margin-top:12px;">
                                    <div class="col" style="flex:1">
                                        <label>Загрузить файл модуля (PHP)</label>
                                        <input type="file" name="module_file" accept=".php" class="box-input" style="margin:0; padding:6px;">
                                    </div>
                                    <div class="col" style="flex:1; font-size:12px; color:var(--text-dim);">
                                        Файл необязателен. Если указан, будет сохранён как `<ID>.php`.
                                    </div>
                                </div>
                            </form>
                        </details>
                        <?php endif; ?>
                        
                        <!-- Order Change Alert -->
                        <?php if (can('modules_reorder')): ?>
                        <div class="order-alert" id="saveOrderBox">
                            <span>⚡ Порядок изменен. Сохранить в меню?</span>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="reorder_modules">
                                <input type="hidden" name="order_json" id="orderJson">
                                <button class="btn btn-sm btn-primary">Сохранить порядок</button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- Modules List -->
                        <div class="mod-list" id="modContainer">
                            <?php if(empty($mod_settings)): ?>
                                <div style="text-align: center; padding: 40px; color: var(--text-dim); border: 1px dashed var(--border); border-radius: 5px;">Модули не установлены</div>
                            <?php else: ?>
                            <?php foreach($mod_settings as $id => $m): 
                                if (!can_module($id)) continue;
                                $mfile = MODULES_DIR.$id.'.php';
                                $jfile = DATA_DIR.$id.'.json';
                                $udir = UPLOADS_DIR.$id;
                                $file_exists = file_exists($mfile);
                                    $json_exists = file_exists($jfile);
                                    $uploads_exists = is_dir($udir);
                                    $size = $file_exists ? round(filesize($mfile)/1024, 2).' KB' : 'err';
                                    $mtime = $file_exists ? filemtime($mfile) : 0;
                                    $moduleAccessMap = is_array($G['module_access_map'] ?? null) ? $G['module_access_map'] : [];
                                    $defaultMgrEnabled = !isset($moduleAccessMap[$id]) || (string) $moduleAccessMap[$id] !== '0';
                                    $modulePublicAccessMap = is_array($G['module_public_access_map'] ?? null) ? $G['module_public_access_map'] : [];
                                    $publicWebhookEnabled = isset($modulePublicAccessMap[$id])
                                        ? ((string) $modulePublicAccessMap[$id] !== '0')
                                        : $defaultMgrEnabled;
                                ?>
                                <div class="mod-item<?php echo $file_exists ? '' : ' missing'; ?>" draggable="<?php echo can('modules_reorder') ? 'true' : 'false'; ?>"
                                     data-id="<?php echo $id; ?>"
                                     data-name="<?php echo strtolower(h($m['name'])); ?>"
                                     data-size="<?php echo $file_exists ? filesize($mfile) : 0; ?>"
                                     data-mtime="<?php echo $mtime; ?>"
                                     data-has-data="<?php echo $json_exists ? '1' : '0'; ?>"
                                     data-has-uploads="<?php echo $uploads_exists ? '1' : '0'; ?>"
                                     data-missing="<?php echo $file_exists ? '0' : '1'; ?>"
                                     data-link="<?php echo ADMIN_FILE . '?module=' . $id; ?>"
                                     data-path="<?php echo str_replace(SITE_ROOT, '', $mfile); ?>">
                                        <?php if (can('modules_reorder')): ?>
                                        <div class="drag-handle" title="Перетащить для сортировки">
                                        <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M7 2a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM7 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
                                    </div>
                                        <?php endif; ?>
                                    <label style="display:flex; align-items:center; margin-right:10px;">
                                        <input type="checkbox" class="mod-check">
                                    </label>
                                    <div class="mod-icon"><?php echo strtoupper(substr($id, 0, 2)); ?></div>
                                    <div class="mod-info">
                                        <a href="?module=<?php echo $id; ?>" class="mod-title"><?php echo h($m['name']); ?></a>
                                        <div class="mod-meta">
                                            <span>ID: <code><?php echo $id; ?></code></span>
                                            <span>Размер: <?php echo $size; ?></span>
                                            <span class="badge-mini <?php echo $file_exists ? 'ok' : 'warn'; ?>"><?php echo $file_exists ? 'Файл' : 'Нет файла'; ?></span>
                                            <span class="badge-mini <?php echo $json_exists ? 'ok' : ''; ?>">Данные</span>
                                            <span class="badge-mini <?php echo $uploads_exists ? 'ok' : ''; ?>">Загрузки</span>
                                            <span class="badge-mini <?php echo $defaultMgrEnabled ? 'ok' : 'warn'; ?>" data-badge-role="manager-default">Менеджеры: <?php echo $defaultMgrEnabled ? 'ON' : 'OFF'; ?></span>
                                            <span class="badge-mini <?php echo $publicWebhookEnabled ? 'ok' : 'warn'; ?>" data-badge-role="public-webhook">TG/Webhook: <?php echo $publicWebhookEnabled ? 'ON' : 'OFF'; ?></span>
                                            <span class="badge-mini">Обновл.: <?php echo $mtime ? date('d.m.Y', $mtime) : '—'; ?></span>
                                        </div>
                                        <?php if (!empty($_SESSION['auth'])): ?>
                                            <div class="mod-access">
                                                <div class="mod-access-title">Доступ и callback-кнопки модуля</div>
                                                <div class="mod-access-status">
                                                    <span class="badge-mini <?php echo $defaultMgrEnabled ? 'ok' : 'warn'; ?>" data-badge-role="manager-default-inline">Менеджеры: <?php echo $defaultMgrEnabled ? 'включено' : 'выключено'; ?></span>
                                                    <span class="badge-mini <?php echo $publicWebhookEnabled ? 'ok' : 'warn'; ?>" data-badge-role="public-webhook-inline">TG/Webhook: <?php echo $publicWebhookEnabled ? 'включено' : 'выключено'; ?></span>
                                                </div>

                                                <form method="POST" class="mod-access-form js-auto-submit" data-success="Общий доступ сохранён">
                                                    <input type="hidden" name="action" value="save_module_default_access">
                                                    <input type="hidden" name="mid" value="<?php echo h($id); ?>">
                                                    <div class="mod-access-grid">
                                                        <label class="mod-access-item">
                                                            <span class="mod-access-name">Разрешить модуль всем менеджерам по умолчанию</span>
                                                            <span class="mod-switch">
                                                                <input type="checkbox" name="module_enabled" value="1" <?php echo $defaultMgrEnabled ? 'checked' : ''; ?>>
                                                                <span class="mod-switch-slider"></span>
                                                            </span>
                                                        </label>
                                                    </div>
                                                    <div class="mod-status-live" data-status-for="save_module_default_access"></div>
                                                </form>

                                                <form method="POST" class="mod-access-form js-auto-submit" style="margin-top:8px;" data-success="Публичный callback доступ сохранён">
                                                    <input type="hidden" name="action" value="save_module_public_access">
                                                    <input type="hidden" name="mid" value="<?php echo h($id); ?>">
                                                    <div class="mod-access-grid">
                                                        <label class="mod-access-item">
                                                            <span class="mod-access-name">Разрешить публичные TG/Webhook callback для модуля</span>
                                                            <span class="mod-switch">
                                                                <input type="checkbox" name="module_public_enabled" value="1" <?php echo $publicWebhookEnabled ? 'checked' : ''; ?>>
                                                                <span class="mod-switch-slider"></span>
                                                            </span>
                                                        </label>
                                                    </div>
                                                    <div class="mod-access-actions">
                                                        <button class="btn btn-secondary btn-sm mod-ping-btn" data-mid="<?php echo h($id); ?>" type="button">Проверить callback</button>
                                                    </div>
                                                    <div class="mod-status-live" data-status-for="save_module_public_access"></div>
                                                </form>

                                                <?php if (!empty($managers)): ?>
                                                    <form method="POST" class="mod-access-form js-auto-submit" style="margin-top:8px;" data-success="Персональные права сохранены">
                                                        <input type="hidden" name="action" value="save_module_access">
                                                        <input type="hidden" name="mid" value="<?php echo h($id); ?>">
                                                        <div class="mod-access-grid">
                                                            <?php foreach ($managers as $mgrId => $mgr): ?>
                                                                <?php
                                                                    $mgrName = trim((string) ($mgr['name'] ?? $mgrId));
                                                                    $mgrPerms = normalize_manager_perms($mgr['perms'] ?? []);
                                                                    $mgrChecked = !empty($mgrPerms['module_full_' . $id]) ? 'checked' : '';
                                                                ?>
                                                                <label class="mod-access-item">
                                                                    <span class="mod-access-name"><?php echo h($mgrName); ?> <code>@<?php echo h($mgrId); ?></code></span>
                                                                    <span class="mod-switch">
                                                                        <input type="checkbox" name="manager_full[]" value="<?php echo h($mgrId); ?>" <?php echo $mgrChecked; ?>>
                                                                        <span class="mod-switch-slider"></span>
                                                                    </span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <div class="mod-status-live" data-status-for="save_module_access"></div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="mod-access-empty">Менеджеры не созданы. Общий тумблер выше работает без менеджеров.</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (can_module_action($id, 'modules_edit_meta')): ?>
                                        <details class="mod-edit" id="edit-<?php echo $id; ?>">
                                            <summary>Редактировать параметры</summary>
                                            <form method="POST" enctype="multipart/form-data" class="app-form mod-edit-form">
                                                <input type="hidden" name="action" value="edit_module_meta">
                                                <input type="hidden" name="old_id" value="<?php echo $id; ?>">
                                                <div class="row" style="align-items:flex-end;">
                                                    <div class="col" style="flex:1;">
                                                        <label>Название</label>
                                                        <input type="text" name="mname" class="box-input" value="<?php echo h($m['name']); ?>" required>
                                                    </div>
                                                    <div class="col" style="flex:1;">
                                                        <label>ID</label>
                                                        <input type="text" name="mid" class="box-input" value="<?php echo h($id); ?>" required>
                                                    </div>
                                                </div>
                                                <div class="row" style="align-items:flex-end; margin-top:10px;">
                                                    <div class="col" style="flex:1;">
                                                        <label>Файл модуля (PHP)</label>
                                                        <input type="file" name="module_file" accept=".php" class="box-input" style="padding:6px;">
                                                    </div>
                                                    <div class="col" style="flex:1; font-size:12px; color:var(--text-dim);">
                                                        При смене ID будут переименованы: файл, JSON и папка загрузок.
                                                    </div>
                                                </div>
                                                <div class="row" style="margin-top:12px; align-items:center;">
                                                    <div class="col" style="flex:0;">
                                                        <button class="btn btn-primary btn-sm">Сохранить</button>
                                                    </div>
                                                    <div class="col" style="flex:0;">
                                                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit('<?php echo $id; ?>')">Отмена</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </details>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mod-actions">
                                        <!-- Copy ID Button -->
                                        <button class="icon-btn" onclick="copyMarker('<?php echo $id; ?>')" title="Скопировать маркер">
                                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/></svg>
                                        </button>
                                        <button class="icon-btn" onclick="copyModuleUrl('<?php echo $id; ?>')" title="Скопировать ссылку">
                                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M10.59 13.41a1 1 0 0 0 1.41 0l3-3a3 3 0 1 0-4.24-4.24l-1.5 1.5a1 1 0 1 0 1.41 1.41l1.5-1.5a1 1 0 1 1 1.41 1.41l-3 3a1 1 0 0 0 0 1.41z"/><path d="M13.41 10.59a1 1 0 0 0-1.41 0l-3 3a3 3 0 1 0 4.24 4.24l1.5-1.5a1 1 0 1 0-1.41-1.41l-1.5 1.5a1 1 0 1 1-1.41-1.41l3-3a1 1 0 0 0 0-1.41z"/></svg>
                                        </button>
                                        <?php if (can_module_action($id, 'modules_edit_meta')): ?>
                                        <button class="icon-btn" onclick="toggleEdit('<?php echo $id; ?>')" title="Редактировать параметры">
                                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M10.5 1a1.5 1.5 0 0 1 1.415.99l.292.792.792.292A1.5 1.5 0 0 1 14 4.5v1a1.5 1.5 0 0 1-1 1.415l-.792.292-.292.792A1.5 1.5 0 0 1 10.5 10h-1a1.5 1.5 0 0 1-1.415-1l-.292-.792-.792-.292A1.5 1.5 0 0 1 6 5.5v-1A1.5 1.5 0 0 1 7 3.085l.792-.292.292-.792A1.5 1.5 0 0 1 9.5 1h1zM8.5 5a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0z"/><path d="M3 8.5a2.5 2.5 0 0 1 2.5-2.5h.55a3.5 3.5 0 0 0 .36 2H5.5A1.5 1.5 0 0 0 4 9.5v1A1.5 1.5 0 0 0 5.5 12h2a1.5 1.5 0 0 0 1.415-1h1.135a3.5 3.5 0 0 0 .36 2H7.5A2.5 2.5 0 0 1 5 10.5v-1z"/></svg>
                                        </button>
                                        <?php endif; ?>
                                        <!-- PHP Code -->
                                        <?php if (can_module_action($id, 'modules_edit_code')): ?>
                                        <a href="?page=modules&edit_module=<?php echo $id; ?>" class="icon-btn" title="Редактировать код">
                                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M10.478 1.647a.5.5 0 1 0-.956-.294l-4 13a.5.5 0 0 0 .956.294l4-13zM4.854 4.146a.5.5 0 0 1 0 .708L1.707 8l3.147 3.146a.5.5 0 0 1-.708.708l-3.5-3.5a.5.5 0 0 1 0-.708l3.5-3.5a.5.5 0 0 1 .708 0zm6.292 0a.5.5 0 0 0 0 .708L14.293 8l-3.147 3.146a.5.5 0 0 0 .708.708l3.5-3.5a.5.5 0 0 0 0-.708l-3.5-3.5a.5.5 0 0 0-.708 0z"/></svg>
                                        </a>
                                        <?php endif; ?>
                                        <!-- Delete -->
                                        <?php if (can_module_action($id, 'modules_delete')): ?>
                                        <a href="?del_mod=<?php echo $id; ?>" class="icon-btn" style="color:#f48771" onclick="return confirm('Полностью удалить модуль?')" title="Удалить">
                                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Toast Notification -->
                        <div id="toast" class="copy-confirm">Скопировано в буфер!</div>

                        <script>
                            const toastEl = document.getElementById('toast');
                            function showToast(message, isError = false) {
                                if (!toastEl) return;
                                toastEl.innerText = message;
                                toastEl.style.display = 'block';
                                toastEl.style.background = isError ? '#b42318' : 'var(--accent)';
                                setTimeout(() => { toastEl.style.display = 'none'; }, 2200);
                            }

                            function setFormStatus(form, message, isError = false) {
                                if (!form) return;
                                const actionInput = form.querySelector('input[name="action"]');
                                const action = actionInput ? actionInput.value : '';
                                const status = action ? form.querySelector(`[data-status-for="${action}"]`) : null;
                                if (!status) return;
                                status.textContent = message;
                                status.classList.remove('ok', 'err');
                                status.classList.add(isError ? 'err' : 'ok');
                            }

                            function applyBadgeState(modItem, role, enabled, onText, offText) {
                                if (!modItem) return;
                                const badge = modItem.querySelector(`[data-badge-role="${role}"]`);
                                if (!badge) return;
                                badge.classList.remove('ok', 'warn');
                                badge.classList.add(enabled ? 'ok' : 'warn');
                                badge.textContent = enabled ? onText : offText;
                            }

                            function updateModuleBadges(form, data) {
                                if (!form || !data) return;
                                const modItem = form.closest('.mod-item');
                                if (!modItem) return;
                                if (typeof data.module_enabled !== 'undefined') {
                                    const enabled = !!parseInt(data.module_enabled, 10);
                                    applyBadgeState(modItem, 'manager-default', enabled, 'Менеджеры: ON', 'Менеджеры: OFF');
                                    applyBadgeState(modItem, 'manager-default-inline', enabled, 'Менеджеры: включено', 'Менеджеры: выключено');
                                }
                                if (typeof data.module_public_enabled !== 'undefined') {
                                    const enabled = !!parseInt(data.module_public_enabled, 10);
                                    applyBadgeState(modItem, 'public-webhook', enabled, 'TG/Webhook: ON', 'TG/Webhook: OFF');
                                    applyBadgeState(modItem, 'public-webhook-inline', enabled, 'TG/Webhook: включено', 'TG/Webhook: выключено');
                                }
                            }

                            async function submitAccessForm(form) {
                                if (!form) return;
                                const fd = new FormData(form);
                                try {
                                    const res = await fetch(window.location.pathname, {
                                        method: 'POST',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                        body: fd
                                    });
                                    const data = await res.json();
                                    if (!res.ok || !data || data.ok !== true) {
                                        const errText = (data && data.error) ? data.error : 'save_failed';
                                        setFormStatus(form, 'Ошибка: ' + errText, true);
                                        showToast('Ошибка сохранения: ' + errText, true);
                                        return;
                                    }
                                    const successText = form.getAttribute('data-success') || 'Сохранено';
                                    setFormStatus(form, successText, false);
                                    updateModuleBadges(form, data);
                                    showToast(successText);
                                } catch (e) {
                                    setFormStatus(form, 'Ошибка соединения', true);
                                    showToast('Ошибка соединения', true);
                                }
                            }

                            // --- COPY MARKER ---
                            function copyMarker(id) {
                                const txt = '<?php echo '<?php /* BLOCK:'; ?>' + id + ' START */ ?>';
                                navigator.clipboard.writeText(txt).then(() => {
                                    showToast('Маркер скопирован: ' + id);
                                });
                            }

                            function toggleEdit(id) {
                                const el = document.getElementById('edit-' + id);
                                if (!el) return;
                                el.open = !el.open;
                                if (el.open) {
                                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                                }
                            }

                            // --- DRAG & DROP LOGIC ---
                            const container = document.getElementById('modContainer');
                            let dragItem = null;

                            if (container && <?php echo can('modules_reorder') ? 'true' : 'false'; ?>) {
                                container.addEventListener('dragstart', e => {
                                    const handle = e.target.closest('.drag-handle');
                                    if (!handle) {
                                        e.preventDefault();
                                        return;
                                    }
                                    dragItem = e.target.closest('.mod-item');
                                    if (dragItem) dragItem.style.opacity = '0.5';
                                    e.dataTransfer.effectAllowed = 'move';
                                });

                                container.addEventListener('dragend', e => {
                                    dragItem.style.opacity = '1';
                                    dragItem = null;
                                    checkOrder();
                                });

                                container.addEventListener('dragover', e => {
                                    e.preventDefault();
                                    const afterElement = getDragAfterElement(container, e.clientY);
                                    if (afterElement == null) {
                                        container.appendChild(dragItem);
                                    } else {
                                        container.insertBefore(dragItem, afterElement);
                                    }
                                });
                            }

                            function getDragAfterElement(container, y) {
                                const draggableElements = [...container.querySelectorAll('.mod-item:not(.dragging)')];
                                return draggableElements.reduce((closest, child) => {
                                    const box = child.getBoundingClientRect();
                                    const offset = y - box.top - box.height / 2;
                                    if (offset < 0 && offset > closest.offset) {
                                        return { offset: offset, element: child };
                                    } else {
                                        return closest;
                                    }
                                }, { offset: Number.NEGATIVE_INFINITY }).element;
                            }

                            function checkOrder() {
                                const ids = Array.from(document.querySelectorAll('.mod-item')).map(el => el.getAttribute('data-id'));
                                document.getElementById('orderJson').value = JSON.stringify(ids);
                                document.getElementById('saveOrderBox').style.display = 'flex';
                            }

                            // --- SELECTION TOOLBAR ---
                            const modSelectAll = document.getElementById('modSelectAll');
                            const modClearSel = document.getElementById('modClearSel');
                            const modCopyIds = document.getElementById('modCopyIds');
                            const modCopyLinks = document.getElementById('modCopyLinks');
                            const modCopyPaths = document.getElementById('modCopyPaths');
                            const modExportCsv = document.getElementById('modExportCsv');
                            const modOnlySelected = document.getElementById('modOnlySelected');
                            const modSelInfo = document.getElementById('modSelInfo');

                            function getSelectedItems() {
                                return Array.from(document.querySelectorAll('.mod-item')).filter(item => {
                                    const cb = item.querySelector('.mod-check');
                                    return cb && cb.checked;
                                });
                            }

                            function updateSelectionInfo() {
                                const selectedCount = getSelectedItems().length;
                                if (modSelInfo) modSelInfo.textContent = `Выбрано: ${selectedCount}`;
                                if (modSelectAll) {
                                    const allItems = Array.from(document.querySelectorAll('.mod-item'));
                                    modSelectAll.checked = allItems.length > 0 && selectedCount === allItems.length;
                                }
                            }

                            document.querySelectorAll('.mod-check').forEach(cb => {
                                cb.addEventListener('change', () => {
                                    updateSelectionInfo();
                                    applyFilterSort();
                                });
                            });

                            if (modSelectAll) {
                                modSelectAll.addEventListener('change', () => {
                                    const checked = modSelectAll.checked;
                                    document.querySelectorAll('.mod-check').forEach(cb => { cb.checked = checked; });
                                    updateSelectionInfo();
                                    applyFilterSort();
                                });
                            }
                            if (modClearSel) {
                                modClearSel.addEventListener('click', () => {
                                    document.querySelectorAll('.mod-check').forEach(cb => { cb.checked = false; });
                                    if (modSelectAll) modSelectAll.checked = false;
                                    updateSelectionInfo();
                                    applyFilterSort();
                                });
                            }
                            if (modOnlySelected) {
                                modOnlySelected.addEventListener('change', applyFilterSort);
                            }

                            // --- TOOLBAR FILTER/SORT ---
                            const modSort = document.getElementById('modSort');
                            const modFilter = document.getElementById('modFilter');
                            const modCountInfo = document.getElementById('modCountInfo');
                            const resetModView = document.getElementById('resetModView');

                            function applyFilterSort() {
                                const term = (document.getElementById('modSearch').value || '').toLowerCase();
                                const filter = modFilter.value;
                                const sortBy = modSort.value;
                                const onlySelected = modOnlySelected && modOnlySelected.checked;
                                const listItems = Array.from(document.querySelectorAll('.mod-item'));

                                // Filter
                                let visible = [];
                                listItems.forEach(item => {
                                    const name = (item.getAttribute('data-name') || '');
                                    const id = item.getAttribute('data-id') || '';
                                    const okSearch = !term || name.includes(term) || id.includes(term);
                                    const cb = item.querySelector('.mod-check');
                                    const okSelected = !onlySelected || (cb && cb.checked);
                                    let okFilter = true;
                                    if (filter === 'has-data') okFilter = item.getAttribute('data-has-data') === '1';
                                    if (filter === 'has-uploads') okFilter = item.getAttribute('data-has-uploads') === '1';
                                    if (filter === 'missing') okFilter = item.getAttribute('data-missing') === '1';
                                    const ok = okSearch && okFilter && okSelected;
                                    item.style.display = ok ? 'flex' : 'none';
                                    if (ok) visible.push(item);
                                });

                                // Sort visible
                                visible.sort((a, b) => {
                                    if (sortBy === 'name') return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
                                    if (sortBy === 'id') return (a.getAttribute('data-id') || '').localeCompare(b.getAttribute('data-id') || '');
                                    if (sortBy === 'size') return (parseInt(b.getAttribute('data-size'), 10) || 0) - (parseInt(a.getAttribute('data-size'), 10) || 0);
                                    if (sortBy === 'mtime') return (parseInt(b.getAttribute('data-mtime'), 10) || 0) - (parseInt(a.getAttribute('data-mtime'), 10) || 0);
                                    return 0;
                                });
                                visible.forEach(item => container.appendChild(item));

                                if (modCountInfo) modCountInfo.textContent = `Показано: ${visible.length}`;
                            }

                            if (modSort) modSort.addEventListener('change', applyFilterSort);
                            if (modFilter) modFilter.addEventListener('change', applyFilterSort);
                            document.getElementById('modSearch').addEventListener('input', applyFilterSort);
                            if (resetModView) resetModView.addEventListener('click', () => {
                                document.getElementById('modSearch').value = '';
                                modFilter.value = 'all';
                                modSort.value = 'mtime';
                                if (modOnlySelected) modOnlySelected.checked = false;
                                applyFilterSort();
                            });
                            updateSelectionInfo();
                            applyFilterSort();

                            // --- COPY MODULE URL ---
                            function copyModuleUrl(id) {
                                const url = window.location.origin + window.location.pathname + '?module=' + id;
                                navigator.clipboard.writeText(url).then(() => {
                                    showToast('Ссылка скопирована: ' + id);
                                });
                            }

                            // --- BULK ACTIONS ---
                            function copyTextList(lines, label) {
                                if (!lines.length) return;
                                navigator.clipboard.writeText(lines.join('\n')).then(() => {
                                    showToast(label);
                                });
                            }

                            // --- LIVE TOGGLES (AUTO SAVE) ---
                            document.querySelectorAll('.mod-access-form.js-auto-submit').forEach(form => {
                                form.addEventListener('submit', e => {
                                    e.preventDefault();
                                    submitAccessForm(form);
                                });
                                form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                                    cb.addEventListener('change', () => submitAccessForm(form));
                                });
                            });

                            // --- PING PUBLIC CALLBACK ---
                            document.querySelectorAll('.mod-ping-btn').forEach(btn => {
                                btn.addEventListener('click', async () => {
                                    const mid = btn.getAttribute('data-mid') || '';
                                    if (!mid) return;
                                    btn.disabled = true;
                                    const oldText = btn.innerText;
                                    btn.innerText = 'Проверка...';
                                    try {
                                        const url = `${window.location.pathname}?module=${encodeURIComponent(mid)}&action=module_ping&_=${Date.now()}`;
                                        const res = await fetch(url, { method: 'GET' });
                                        const data = await res.json();
                                        const ok = !!(data && data.ok);
                                        if (!ok) {
                                            showToast('Проверка не удалась', true);
                                        } else if (!data.module_file_exists) {
                                            showToast('Файл модуля не найден', true);
                                        } else if (!data.public_enabled) {
                                            showToast('Публичный доступ отключен', true);
                                        } else {
                                            showToast('Callback доступен: ' + mid);
                                        }
                                    } catch (e) {
                                        showToast('Ошибка проверки callback', true);
                                    } finally {
                                        btn.disabled = false;
                                        btn.innerText = oldText;
                                    }
                                });
                            });

                            if (modCopyIds) modCopyIds.addEventListener('click', () => {
                                const selected = getSelectedItems();
                                const items = selected.length ? selected : Array.from(document.querySelectorAll('.mod-item'));
                                const ids = items.map(i => i.getAttribute('data-id'));
                                copyTextList(ids, `ID скопированы: ${ids.length}`);
                            });

                            if (modCopyLinks) modCopyLinks.addEventListener('click', () => {
                                const selected = getSelectedItems();
                                const items = selected.length ? selected : Array.from(document.querySelectorAll('.mod-item'));
                                const links = items.map(i => window.location.origin + window.location.pathname + '?module=' + i.getAttribute('data-id'));
                                copyTextList(links, `Ссылки скопированы: ${links.length}`);
                            });

                            if (modCopyPaths) modCopyPaths.addEventListener('click', () => {
                                const selected = getSelectedItems();
                                const items = selected.length ? selected : Array.from(document.querySelectorAll('.mod-item'));
                                const paths = items.map(i => i.getAttribute('data-path') || '');
                                copyTextList(paths, `Пути скопированы: ${paths.length}`);
                            });

                            if (modExportCsv) modExportCsv.addEventListener('click', () => {
                                const selected = getSelectedItems();
                                const items = selected.length ? selected : Array.from(document.querySelectorAll('.mod-item'));
                                if (!items.length) return;
                                const rows = [
                                    ['name','id','has_data','has_uploads','size_bytes','mtime','link','path']
                                ];
                                items.forEach(i => {
                                    rows.push([
                                        i.getAttribute('data-name') || '',
                                        i.getAttribute('data-id') || '',
                                        i.getAttribute('data-has-data') || '0',
                                        i.getAttribute('data-has-uploads') || '0',
                                        i.getAttribute('data-size') || '0',
                                        i.getAttribute('data-mtime') || '0',
                                        window.location.origin + window.location.pathname + '?module=' + (i.getAttribute('data-id') || ''),
                                        i.getAttribute('data-path') || ''
                                    ]);
                                });
                                const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
                                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                                const url = URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                a.href = url;
                                a.download = 'modules_export.csv';
                                document.body.appendChild(a);
                                a.click();
                                a.remove();
                                URL.revokeObjectURL(url);
                            });
                        </script>
                    <?php } break;

case 'js': 
                    // === LOGIC: REORDER JS ===
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_js') {
                        require_perm_or_redirect('js_reorder', 'js');
                        $order_ids = json_decode($_POST['order_json'], true);
                        if (is_array($order_ids)) {
                            $new_plugins = [];
                            foreach ($order_ids as $id) {
                                if (isset($G['js_plugins'][$id])) {
                                    $new_plugins[$id] = $G['js_plugins'][$id];
                                }
                            }
                            $G['js_plugins'] = $new_plugins;
                            save_global_settings($G);
                            compile_plugins_bundle($G);
                            redirect_with_message('success', 'Порядок загрузки плагинов обновлен', ['page' => 'js']);
                        }
                    }

                    // === VIEW 1: IDE EDITOR ===
                    $eid = $_GET['edit_js'] ?? null;
                    if ($eid) {
                        require_perm_or_redirect('js_edit', 'js');
                        $p_info = $G['js_plugins'][$eid] ?? null;
                        if(!$p_info) { echo "Plugin not found"; break; }
                        
                        $file_path = JS_PLUGINS_DIR . $p_info['file'];
                        $code = file_exists($file_path) ? file_get_contents($file_path) : "";
                        $fsize = file_exists($file_path) ? round(filesize($file_path)/1024, 2) . ' KB' : '0 KB';
                        $is_css = (strpos($code, '<style') !== false);
                        ?>
                        <style>
                            .ide-container { display: flex; height: calc(100vh - 160px); border: 1px solid var(--border); background: #1e1e1e; position: relative; }
                            .line-numbers { background: #252526; color: #5a5a5a; text-align: right; padding: 15px 10px; width: 45px; border-right: 1px solid #333; font-family: var(--font-code); font-size: 13px; line-height: 21px; user-select: none; overflow: hidden; }
                            .ide-textarea { flex: 1; border: none; background: transparent; color: #d4d4d4; padding: 15px; font-family: var(--font-code); font-size: 13px; line-height: 21px; resize: none; outline: none; white-space: pre; overflow: auto; }
                            .type-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: bold; font-family: sans-serif; }
                            .is-js { background: #f1e05a; color: #000; }
                            .is-css { background: #563d7c; color: #fff; }
                        </style>

                        <div class="panel-header">
                            <div>
                                <h1 style="display:flex; align-items:center; gap: 10px;">
                                    <span style="opacity:0.5">Edit:</span> 
                                    <span style="color:var(--text-bright)"><?php echo h($p_info['name']); ?></span>
                                    <span class="type-badge <?php echo $is_css ? 'is-css' : 'is-js'; ?>"><?php echo $is_css ? 'CSS INJECT' : 'JS'; ?></span>
                                </h1>
                                <div style="font-size:11px; color:var(--text-dim); margin-top:5px; font-family:var(--font-code)">
                                    <?php echo $file_path; ?> • <?php echo $fsize; ?>
                                </div>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <a href="?page=js" class="btn btn-secondary">Отмена</a>
                                <button type="button" onclick="submitJsCode()" class="btn btn-primary">Сохранить (Ctrl+S)</button>
                            </div>
                        </div>

                        <form id="jsForm" method="POST" style="height:100%;">
                            <input type="hidden" name="js_action" value="edit">
                            <input type="hidden" name="pid" value="<?php echo $eid; ?>">
                            
                            <div class="row" style="margin-bottom: 10px;">
                                <div class="col" style="flex:1;">
                                    <input type="text" name="pname" value="<?php echo h($p_info['name']); ?>" class="box-input" placeholder="Название плагина" style="background:#252526; border-color:#3e3e42; margin:0;">
                                </div>
                            </div>

                            <div class="ide-container">
                                <div class="line-numbers" id="lineNums">1</div>
                                <textarea name="code" id="jsEditor" class="ide-textarea" spellcheck="false" 
                                oninput="updateLines()" onscroll="syncScroll()"><?php echo h($code); ?></textarea>
                            </div>
                        </form>

                        <script>
                            const editor = document.getElementById('jsEditor');
                            const lineBox = document.getElementById('lineNums');
                            function updateLines() { lineBox.innerHTML = Array(editor.value.split('\n').length).fill(0).map((_, i) => i + 1).join('<br>'); }
                            function syncScroll() { lineBox.scrollTop = editor.scrollTop; }
                            function submitJsCode() { document.getElementById('jsForm').submit(); }
                            editor.addEventListener('keydown', e => {
                                if(e.key=='Tab'){ e.preventDefault(); const s=this.selectionStart, n=this.selectionEnd; this.value=this.value.substring(0,s)+"    "+this.value.substring(n); this.selectionStart=this.selectionEnd=s+4; }
                                if((e.ctrlKey||e.metaKey)&&e.key==='s'){ e.preventDefault(); submitJsCode(); }
                            });
                            updateLines();
                        </script>

                    <?php 
                    // === VIEW 2: MANAGER ===
                    } else { 
                        // Calc Bundle Stats
                        $js_bundle_size = file_exists(PLUGINS_JS_FILE) ? round(filesize(PLUGINS_JS_FILE)/1024, 1).' KB' : '0 KB';
                        $css_bundle_size = file_exists(PLUGINS_CSS_FILE) ? round(filesize(PLUGINS_CSS_FILE)/1024, 1).' KB' : '0 KB';
                        $p_count = count($PLUGINS);
                    ?>
                        <style>
                            .bundle-stat { background: #252526; border: 1px solid var(--border); padding: 15px; border-radius: 4px; display:flex; align-items:center; gap: 15px; flex:1; }
                            .b-icon { width:40px; height:40px; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; border-radius:4px; font-weight:bold; font-size:12px; }
                            .p-item { background: #252526; border: 1px solid var(--border); margin-bottom: 8px; padding: 12px; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; transition:0.2s; }
                            .p-item:hover { background: #2a2a2a; border-color: #555; }
                            .p-item.dragging { opacity: 0.5; border: 1px dashed var(--accent); }
                            
                            .drag-h { cursor: grab; padding: 5px; opacity: 0.3; margin-right: 10px; }
                            .drag-h:hover { opacity: 1; }
                            
                            .tag { font-size: 10px; padding: 2px 6px; border-radius: 3px; font-weight: 600; text-transform: uppercase; margin-left: 10px; vertical-align: middle; letter-spacing: 0.5px; }
                            .tag.js { border: 1px solid #f1e05a; color: #f1e05a; }
                            .tag.css { border: 1px solid #563d7c; color: #a88cd5; }
                        </style>

                        <!-- HEADERS & STATS -->
                        <div class="panel-header">
                            <div>
                                <h1>Менеджер расширений</h1>
                                <p style="color:var(--text-dim); margin-top:5px; font-size:13px;">Подключайте аналитику, слайдеры и CSS-библиотеки.</p>
                            </div>
                        </div>

                        <div class="row" style="margin-bottom: 25px;">
                            <div class="bundle-stat">
                                <div class="b-icon" style="color: #f1e05a;">JS</div>
                                <div>
                                    <div style="font-size: 18px; font-weight: 700; color: white;"><?php echo $js_bundle_size; ?></div>
                                    <div style="font-size: 11px; color: var(--text-dim);">Размер бандла</div>
                                </div>
                            </div>
                            <div class="bundle-stat">
                                <div class="b-icon" style="color: #c678dd;">CSS</div>
                                <div>
                                    <div style="font-size: 18px; font-weight: 700; color: white;"><?php echo $css_bundle_size; ?></div>
                                    <div style="font-size: 11px; color: var(--text-dim);">Бандл стилей</div>
                                </div>
                            </div>
                            <div class="bundle-stat">
                                <div class="b-icon" style="color: var(--accent);">ALL</div>
                                <div>
                                    <div style="font-size: 18px; font-weight: 700; color: white;"><?php echo $p_count; ?></div>
                                    <div style="font-size: 11px; color: var(--text-dim);">Активных плагинов</div>
                                </div>
                            </div>
                        </div>

                        <!-- CREATE NEW TOGGLE -->
                        <?php if (can('js_add')): ?>
                        <details style="border: 1px solid var(--border); background: #252526; border-radius: 4px; margin-bottom: 20px; overflow:hidden;">
                            <summary style="background: rgba(255,255,255,0.02); padding: 12px 15px; font-weight:600; font-size:12px;">+ Подключить новый плагин</summary>
                            <form method="POST" class="app-form" style="padding: 20px;">
                                <input type="hidden" name="js_action" value="add">
                                <div class="row">
                                    <div class="col" style="flex:1">
                                        <label>Название (для админки)</label>
                                        <input type="text" id="newJsName" name="pname" placeholder="Напр. Google Analytics" required>
                                    </div>
                                    <div class="col" style="flex:1">
                                        <label>ID Файла (англ)</label>
                                        <input type="text" id="newJsID" name="pid" placeholder="google-analytics" required readonly style="background:#1e1e1e; cursor:not-allowed;">
                                    </div>
                                    <div class="col" style="flex:0">
                                        <label>&nbsp;</label>
                                        <button class="btn btn-primary" style="height:36px;">Создать</button>
                                    </div>
                                </div>
                                <label style="margin-top:0;">Код (JavaScript или &lt;style&gt;CSS&lt;/style&gt;)</label>
                                <textarea name="code" class="code-editor" rows="6" placeholder="// Вставьте код плагина сюда..."></textarea>
                            </form>
                        </details>
                        <?php endif; ?>

                        <!-- REORDER ALERT -->
                        <?php if (can('js_reorder')): ?>
                        <div id="reorderAlert" style="display:none; background:rgba(0,122,204,0.2); border:1px solid var(--accent); padding:10px 15px; margin-bottom:15px; border-radius:4px; align-items:center; justify-content:space-between; animation:fadeIn 0.3s;">
                            <span>Порядок загрузки изменен. Сохранить?</span>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="reorder_js">
                                <input type="hidden" name="order_json" id="jsOrderJson">
                                <button class="btn btn-primary btn-sm">Сохранить порядок</button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- LIST -->
                        <div id="jsList">
                            <?php if(empty($PLUGINS)): ?>
                                <div style="text-align:center; padding:30px; color:var(--text-dim); border:1px dashed var(--border); border-radius:5px;">Нет установленных плагинов</div>
                            <?php else: 
                                foreach($PLUGINS as $id => $p): 
                                    $file = JS_PLUGINS_DIR . $p['file'];
                                    $size = file_exists($file) ? filesize($file) : 0;
                                    $is_style = false;
                                    if(file_exists($file)) {
                                        $snippet = file_get_contents($file, false, null, 0, 100);
                                        if(strpos($snippet, '<style') !== false) $is_style = true;
                                    }
                            ?>
                                <div class="p-item" draggable="<?php echo can('js_reorder') ? 'true' : 'false'; ?>" data-id="<?php echo $id; ?>">
                                    <div style="display:flex; align-items:center;">
                                        <?php if (can('js_reorder')): ?>
                                        <div class="drag-h" title="Тяни чтобы сортировать">⋮⋮</div>
                                        <?php endif; ?>
                                        <div style="width: 32px; height: 32px; background: #333; border-radius: 4px; display:flex; align-items:center; justify-content:center; margin-right: 12px; font-weight:bold; font-size:14px; color: #fff;">
                                            <?php echo strtoupper(substr($id,0,1)); ?>
                                        </div>
                                        <div>
                                            <?php if (can('js_edit')): ?>
                                            <a href="?page=js&edit_js=<?php echo $id; ?>" style="font-weight:600; color:white; display:block;">
                                                <?php echo h($p['name']); ?>
                                            </a>
                                            <?php else: ?>
                                            <span style="font-weight:600; color:white; display:block;"><?php echo h($p['name']); ?></span>
                                            <?php endif; ?>
                                            <div style="font-size:11px; color:var(--text-dim); margin-top:2px;">
                                                <?php echo $id; ?>.js • <?php echo round($size/1024, 2); ?> KB
                                                <?php if($is_style) echo '<span class="tag css">CSS</span>'; else echo '<span class="tag js">JS</span>'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display:flex; gap:10px;">
                                        <?php if (can('js_edit')): ?>
                                        <a href="?page=js&edit_js=<?php echo $id; ?>" class="btn btn-secondary btn-sm" title="Редактировать код">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (can('js_delete')): ?>
                                        <a href="?del_js=<?php echo $id; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Удалить плагин <?php echo h($p['name']); ?>?')" title="Удалить">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <script>
                            // Auto Slug
                            const jn = document.getElementById('newJsName');
                            const ji = document.getElementById('newJsID');
                            if(jn) jn.addEventListener('input', e => ji.value = e.target.value.toLowerCase().replace(/[^a-z0-9 ]/g,'').trim().replace(/\s+/g,'-'));

                            // D&D Sort
                            const list = document.getElementById('jsList');
                            let dragEl = null;
                            if(list && <?php echo can('js_reorder') ? 'true' : 'false'; ?>) {
                                list.addEventListener('dragstart', e => { dragEl = e.target; e.target.classList.add('dragging'); });
                                list.addEventListener('dragend', e => { 
                                    dragEl.classList.remove('dragging'); dragEl = null;
                                    updateOrder();
                                });
                                list.addEventListener('dragover', e => {
                                    e.preventDefault();
                                    const afterElement = getDragAfterElement(list, e.clientY);
                                    if(afterElement == null) list.appendChild(dragEl);
                                    else list.insertBefore(dragEl, afterElement);
                                });
                            }
                            function getDragAfterElement(container, y) {
                                const els = [...container.querySelectorAll('.p-item:not(.dragging)')];
                                return els.reduce((closest, child) => {
                                    const box = child.getBoundingClientRect();
                                    const offset = y - box.top - box.height / 2;
                                    if(offset < 0 && offset > closest.offset) return { offset: offset, element: child };
                                    else return closest;
                                }, { offset: Number.NEGATIVE_INFINITY }).element;
                            }
                            function updateOrder() {
                                const ids = Array.from(list.querySelectorAll('.p-item')).map(el => el.getAttribute('data-id'));
                                document.getElementById('jsOrderJson').value = JSON.stringify(ids);
                                document.getElementById('reorderAlert').style.display = 'flex';
                            }
                        </script>
                    <?php } break;

case 'settings': 
                    // Defaults for new fields
                    $editor_fs = $G['editor_font_size'] ?? '13';
                    $editor_wrap = $G['editor_word_wrap'] ?? 0;
                    $tz = $G['timezone'] ?? 'Europe/Chisinau';
                    $sess_time = $G['session_timeout'] ?? 30;
                    $ui_font_size = $G['ui_font_size'] ?? 13;
                    $ui_radius = $G['ui_radius'] ?? 6;
                    $ui_shadow = !empty($G['ui_shadow']);
                    $ui_blur = !empty($G['ui_blur']);
                    $ui_animations = !empty($G['ui_animations']);
                    $ui_compact = !empty($G['ui_compact']);
                    $ui_density = $G['ui_density'] ?? 'cozy';
                    $sidebar_width = $G['sidebar_width'] ?? 220;
                    $theme_preset = $G['theme_preset'] ?? 'dark';
                    $default_page = $G['default_page'] ?? 'panel';
                    $color_bg_app = $G['color_bg_app'] ?? '';
                    $color_bg_sidebar = $G['color_bg_sidebar'] ?? '';
                    $color_bg_panel = $G['color_bg_panel'] ?? '';
                    $color_text = $G['color_text'] ?? '';
                    $color_text_dim = $G['color_text_dim'] ?? '';
                    $color_border = $G['color_border'] ?? '';
                    $custom_css = $G['custom_css'] ?? '';
                    $custom_logo = $G['custom_logo'] ?? '';
                    $show_kpi = !isset($G['show_kpi']) || !empty($G['show_kpi']);
                    $show_recent = !isset($G['show_recent']) || !empty($G['show_recent']);
                ?>
                    <style>
                        /* PRO SETTINGS STYLES */
                        .pro-settings { display: flex; height: calc(100vh - 150px); border: 1px solid var(--border); border-radius: 6px; background: #252526; overflow: hidden; }
                        
                        /* Sidebar Tabs */
                        .settings-sidebar { width: 220px; background: rgba(0,0,0,0.15); border-right: 1px solid var(--border); display: flex; flex-direction: column; }
                        .s-tab { padding: 15px 20px; color: var(--text-dim); cursor: pointer; border-left: 3px solid transparent; font-size: 13px; display: flex; align-items: center; gap: 10px; transition: 0.2s; }
                        .s-tab:hover { background: rgba(255,255,255,0.03); color: var(--text-main); }
                        .s-tab.active { background: #2a2a2a; color: var(--text-bright); border-left-color: var(--accent); }
                        .s-tab svg { width: 16px; height: 16px; opacity: 0.7; }
                        
                        /* Content Area */
                        .settings-content { flex: 1; padding: 0; overflow-y: auto; background: #252526; }
                        .s-pane { display: none; padding: 30px; animation: fadeIn 0.3s; }
                        .s-pane.active { display: block; }
                        
                        /* Sections */
                        .s-group { margin-bottom: 30px; }
                        .s-group h3 { font-size: 14px; text-transform: uppercase; color: var(--accent); margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 10px; letter-spacing: 0.5px; }
                        
                        /* Form Controls */
                        .control-row { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 25px; gap: 20px; }
                        .control-label strong { display: block; color: var(--text-bright); font-weight: 500; font-size: 13px; margin-bottom: 5px; }
                        .control-label span { color: var(--text-dim); font-size: 12px; display: block; max-width: 400px; line-height: 1.4; }
                        .control-input { flex-shrink: 0; width: 200px; }
                        
                        /* Switch Toggle */
                        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
                        .switch input { opacity: 0; width: 0; height: 0; }
                        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #3e3e42; transition: .4s; border-radius: 24px; }
                        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .2s; border-radius: 50%; }
                        input:checked + .slider { background-color: var(--accent); }
                        input:checked + .slider:before { transform: translateX(20px); }

                        /* Range Input */
                        input[type=range] { width: 100%; -webkit-appearance: none; background: transparent; margin: 10px 0; }
                        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 16px; width: 16px; border-radius: 50%; background: var(--accent); margin-top: -6px; cursor: pointer; }
                        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #444; border-radius: 2px; }

                        /* Custom Inputs */
                        .box-input { width: 100%; background: #1e1e1e; border: 1px solid var(--border); color: #fff; padding: 8px 10px; border-radius: 3px; }
                        .color-wrapper { display: flex; align-items: center; background: #1e1e1e; border: 1px solid var(--border); border-radius: 3px; padding: 4px; gap: 10px; }
                        input[type=color] { border: none; background: none; width: 30px; height: 30px; cursor: pointer; padding: 0; }

                        /* MANAGERS */
                        .mgr-title { font-size: 18px; letter-spacing: 0.4px; }
                        .mgr-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
                        .mgr-sub { font-size: 14px; color: var(--text-bright); margin-bottom: 6px; }
                        .mgr-note { font-size: 12px; color: var(--text-dim); }
                        .mgr-card { background:#1c1c1c; border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:16px; }
                        .mgr-card-title { font-size: 15px; font-weight: 600; margin-bottom: 12px; color: var(--text-bright); }
                        .mgr-form-row { display:flex; gap:12px; flex-wrap:wrap; }
                        .mgr-input { height: 36px; font-size: 13px; }
                        .mgr-hint { font-size: 12px; color: var(--text-dim); margin-top: 10px; }
                        .mgr-list { display:flex; flex-direction:column; gap:16px; }
                        .mgr-empty { padding:16px; border:1px dashed var(--border); border-radius:8px; color:var(--text-dim); font-size:13px; }
                        .mgr-item { background:#181818; border:1px solid var(--border); border-radius:10px; padding:16px; }
                        .mgr-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
                        .mgr-name { font-size: 16px; font-weight: 600; color: var(--text-bright); }
                        .mgr-login { font-size: 12px; color: var(--text-dim); }
                        .mgr-actions { display:flex; gap:8px; flex-wrap:wrap; }
                        .mgr-fields { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:12px; margin:12px 0; }
                        .mgr-label { font-size: 12px; color: var(--text-dim); display:block; margin-bottom:6px; }
                        .mgr-modules { background:#151515; border:1px solid #2a2a2a; border-radius:8px; padding:12px; margin-bottom:10px; }
                        .mgr-modules-head { margin-bottom:10px; }
                        .mgr-modules-head label { display:flex; align-items:center; gap:8px; color:var(--text-bright); font-size:13px; font-weight:600; }
                        .mgr-modules-list { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:8px 12px; }
                        .mgr-modules-list label { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--text-dim); margin:0; }
                        .mgr-modules-list input[disabled] { opacity:0.5; }
                        .mgr-advanced { border:1px dashed #303030; border-radius:8px; overflow:hidden; margin-top:10px; }
                        .mgr-advanced summary { cursor:pointer; padding:10px 12px; color:var(--text-dim); font-size:12px; user-select:none; }
                        .mgr-advanced[open] summary { color:var(--text-bright); border-bottom:1px solid #2a2a2a; }
                        .mgr-perms { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:12px; }
                        .mgr-perm-group { background:#151515; border:1px solid #2a2a2a; border-radius:8px; padding:12px; }
                        .mgr-perm-group h5 { margin:0 0 10px; font-size: 13px; color: var(--text-bright); text-transform: uppercase; letter-spacing: 0.5px; }
                        .mgr-perm-group label { display:flex; gap:8px; align-items:center; font-size: 13px; color: var(--text-dim); margin-bottom:6px; }
                        .mgr-footer { margin-top: 14px; }
                    </style>

                    <div class="panel-header">
                        <h1>Центр управления</h1>
                        <?php if (can('settings_save')): ?>
                        <button type="submit" form="mainSettingsForm" class="btn btn-primary" style="padding: 0 25px;">Сохранить все изменения</button>
                        <?php endif; ?>
                    </div>

                    <form id="mainSettingsForm" method="POST" class="pro-settings">
                        <input type="hidden" name="action" value="save_global">

                        <!-- SIDEBAR -->
                        <div class="settings-sidebar">
                            <div class="s-tab active" onclick="switchTab('tab-general', this)">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                Общие
                            </div>
                            <div class="s-tab" onclick="switchTab('tab-editor', this)">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6-6-6"/><path d="M8 6l-6 6 6 6"/></svg>
                                Редактор кода
                            </div>
                            <div class="s-tab" onclick="switchTab('tab-webhooks', this)">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                Webhooks & API
                            </div>
            <div class="s-tab" onclick="switchTab('tab-system', this)">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Система
            </div>
                            <?php if (can('managers_manage')): ?>
                            <div class="s-tab" onclick="switchTab('tab-users', this)">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Пользователи
                            </div>
                            <?php endif; ?>
            <?php if (can('backup_download')): ?>
            <div class="s-tab" onclick="switchTab('tab-backup', this)">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Бэкап
            </div>
            <?php endif; ?>
        </div>

                        <!-- CONTENT: GENERAL -->
                        <div class="settings-content">
                            
                            <!-- TAB 1: General -->
                            <div id="tab-general" class="s-pane active">
                                <div class="s-group">
                                    <h3>Персонализация</h3>
                                    
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Название Панели</strong>
                                            <span>Отображается в заголовке вкладки браузера и в сайдбаре.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="admin_title" class="box-input" value="<?php echo h($TITLE); ?>">
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Акцентный цвет (Тема)</strong>
                                            <span>Основной цвет кнопок, ссылок и активных элементов.</span>
                                        </div>
                                        <div class="control-input">
                                            <div class="color-wrapper">
                                                <input type="color" name="accent_color" id="accentPicker" value="<?php echo h($ACCENT); ?>">
                                                <input type="text" id="accentText" value="<?php echo h($ACCENT); ?>" 
                                                       style="background:none; border:none; color:white; width:60px; font-family:var(--font-code);">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Логотип / текст бренда</strong>
                                            <span>Отображается в левом верхнем блоке (если заполнено).</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="custom_logo" class="box-input" value="<?php echo h($custom_logo); ?>" placeholder="Напр. Code Craft Admin">
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Безопасность учетной записи</h3>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Сменить пароль</strong>
                                            <span style="color:var(--danger)">Оставьте пустым, чтобы не менять текущий.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" style="display:none"> <!-- Анти-автозаполнение -->
                                            <input type="password" style="display:none">
                                            <input type="password" name="new_pass" class="box-input" placeholder="Новый пароль" autocomplete="new-password">
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Поведение и интерфейс</h3>
                                    
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Стартовая страница</strong>
                                            <span>Какая страница открывается по умолчанию.</span>
                                        </div>
                                        <div class="control-input">
                                            <select name="default_page" class="box-input">
                                                <option value="panel" <?php echo $default_page=='panel'?'selected':''; ?>>Панель</option>
                                                <option value="modules" <?php echo $default_page=='modules'?'selected':''; ?>>Модули</option>
                                                <option value="js" <?php echo $default_page=='js'?'selected':''; ?>>JS Плагины</option>
                                                <option value="settings" <?php echo $default_page=='settings'?'selected':''; ?>>Настройки</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Показывать KPI на панели</strong>
                                            <span>Скрыть/показать блоки статистики.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="show_kpi" <?php echo $show_kpi ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Показывать “Недавние” в сайдбаре</strong>
                                            <span>Список последних посещённых модулей.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="show_recent" <?php echo $show_recent ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Меню</h3>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Пункты “Основное”</strong>
                                            <span>Включайте/выключайте разделы и задавайте подписи.</span>
                                        </div>
                                        <div class="control-input" style="width:260px;">
                                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:var(--text-dim);">
                                                <input type="checkbox" name="nav_show_panel" <?php echo $nav_show_panel?'checked':''; ?>> <?php echo h($nav_label_panel); ?>
                                            </label>
                                            <input type="text" name="nav_label_panel" class="box-input" value="<?php echo h($nav_label_panel); ?>" placeholder="Подпись Панели" style="margin-bottom:10px;">

                                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:var(--text-dim);">
                                                <input type="checkbox" name="nav_show_modules" <?php echo $nav_show_modules?'checked':''; ?>> <?php echo h($nav_label_modules); ?>
                                            </label>
                                            <input type="text" name="nav_label_modules" class="box-input" value="<?php echo h($nav_label_modules); ?>" placeholder="Подпись Модулей" style="margin-bottom:10px;">

                                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:var(--text-dim);">
                                                <input type="checkbox" name="nav_show_js" <?php echo $nav_show_js?'checked':''; ?>> <?php echo h($nav_label_js); ?>
                                            </label>
                                            <input type="text" name="nav_label_js" class="box-input" value="<?php echo h($nav_label_js); ?>" placeholder="Подпись JS" style="margin-bottom:10px;">

                                            <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:var(--text-dim);">
                                                <input type="checkbox" name="nav_show_settings" <?php echo $nav_show_settings?'checked':''; ?>> <?php echo h($nav_label_settings); ?>
                                            </label>
                                            <input type="text" name="nav_label_settings" class="box-input" value="<?php echo h($nav_label_settings); ?>" placeholder="Подпись Настроек">
                                        </div>
                                    </div>

                                    <div class="control-row">
        <div class="control-label">
            <strong>Модули в меню</strong>
            <span>Сортировка и свернутость списка модулей.</span>
        </div>
        <div class="control-input" style="width:220px;">
            <select name="menu_modules_sort" class="box-input" style="margin-bottom:8px;">
                <option value="manual" <?php echo $menu_modules_sort=='manual'?'selected':''; ?>>Как в настройках (ручной порядок)</option>
                <option value="alpha" <?php echo $menu_modules_sort=='alpha'?'selected':''; ?>>По алфавиту</option>
            </select>
            <label class="switch" style="margin-bottom:10px;">
                <input type="checkbox" name="menu_modules_collapsed" <?php echo $menu_modules_collapsed?'checked':''; ?>>
                <span class="slider"></span>
            </label>
            <span style="font-size:12px; color:var(--text-dim);">Сворачивать “Активные модули” по умолчанию</span>
        </div>
    </div>

    <div class="control-row">
        <div class="control-label">
            <strong>Компактное меню</strong>
            <span>Уменьшенные отступы и шрифт в сайдбаре.</span>
        </div>
        <div class="control-input">
            <label class="switch">
                <input type="checkbox" name="menu_compact" <?php echo $menu_compact?'checked':''; ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="control-row">
        <div class="control-label">
            <strong>Вид меню</strong>
            <span>Выберите визуальный стиль: rail, ribbon, neon, metro или классика.</span>
        </div>
        <div class="control-input" style="width:220px;">
            <select name="menu_style" class="box-input">
                <option value="classic" <?php echo $menu_style=='classic'?'selected':''; ?>>Classic</option>
                <option value="rail" <?php echo $menu_style=='rail'?'selected':''; ?>>Rail</option>
                <option value="ribbon" <?php echo $menu_style=='ribbon'?'selected':''; ?>>Ribbon</option>
                <option value="neon" <?php echo $menu_style=='neon'?'selected':''; ?>>Neon</option>
                <option value="metro" <?php echo $menu_style=='metro'?'selected':''; ?>>Metro</option>
            </select>
        </div>
    </div>

    <div class="control-row">
        <div class="control-label">
            <strong>Быстрые ссылки (до 3 шт.)</strong>
            <span>Появятся под блоком “Основное”. Поля пустые — ссылка скрыта.</span>
        </div>
        <div class="control-input" style="width:360px;">
            <?php 
                $max_links = 3;
                for ($i=0; $i<$max_links; $i++):
                    $cl = $nav_custom_links[$i] ?? ['title'=>'','url'=>'','target'=>'_blank'];
            ?>
                <div style="display:flex; gap:6px; margin-bottom:8px;">
                    <input type="text" name="custom_link_title[]" class="box-input" value="<?php echo h($cl['title']); ?>" placeholder="Название" style="flex:1;">
                    <input type="text" name="custom_link_url[]" class="box-input" value="<?php echo h($cl['url']); ?>" placeholder="https://..." style="flex:1;">
                    <select name="custom_link_target[]" class="box-input" style="width:90px;">
                        <option value="_blank" <?php echo ($cl['target'] ?? '')=='_blank'?'selected':''; ?>>Новая</option>
                        <option value="_self" <?php echo ($cl['target'] ?? '')=='_self'?'selected':''; ?>>Текущая</option>
                    </select>
                </div>
            <?php endfor; ?>
        </div>
    </div>
                                </div>
                            </div>

                            <!-- TAB 2: Code Editor -->
                            <div id="tab-editor" class="s-pane">
                                <div class="s-group">
                                    <h3>Настройки Редактора (IDE)</h3>
                                    
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Размер шрифта</strong>
                                            <span>Влияет на размер текста в редакторах PHP, HTML, CSS. (Текущий: <span id="fsVal"><?php echo $editor_fs; ?></span>px)</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="range" name="editor_font_size" min="10" max="24" value="<?php echo $editor_fs; ?>" 
                                                   oninput="document.getElementById('fsVal').innerText = this.value">
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Перенос строк (Word Wrap)</strong>
                                            <span>Если включено, длинные строки кода будут переноситься на новую строку.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="editor_word_wrap" <?php echo $editor_wrap ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="card" style="margin-top:20px; border-style:dashed;">
                                        <div class="card-body" style="font-family: var(--font-code); opacity: 0.7;">
                                            <span style="color:#569cd6">function</span> <span style="color:#dcdcaa">preview</span>() {<br>
                                            &nbsp;&nbsp;<span style="color:#6a9955">// Ваши настройки применяются здесь</span><br>
                                            }
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB: Webhooks & API -->
                            <div id="tab-webhooks" class="s-pane">
                                <div class="s-group">
                                    <h3>Безопасность внешних вызовов</h3>
                                    <p class="mgr-note" style="margin-bottom: 20px;">Включите типы префиксов (Action), которые должны работать без авторизации в админке.</p>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Telegram (tg_*)</strong>
                                            <span>Разрешить входящие запросы и кнопки от ботов.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="allow_public_tg" <?php echo ($G['allow_public_tg'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Webhooks (webhook_*)</strong>
                                            <span>Разрешить внешние хуки от сторонних сервисов.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="allow_public_webhook" <?php echo ($G['allow_public_webhook'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Cron & Workers (cron_*, run_*)</strong>
                                            <span>Разрешить вызовы планировщика и фоновых задач.</span>
                                        </div>
                                        <div class="control-input" style="display:flex; gap: 10px;">
                                            <label class="switch" title="Cron">
                                                <input type="checkbox" name="allow_public_cron" <?php echo ($G['allow_public_cron'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                            <label class="switch" title="Run">
                                                <input type="checkbox" name="allow_public_run" <?php echo ($G['allow_public_run'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>API & Callbacks (api_*, callback_*)</strong>
                                            <span>Разрешить программные интерфейсы и обратные вызовы.</span>
                                        </div>
                                        <div class="control-input" style="display:flex; gap: 10px;">
                                            <label class="switch" title="API">
                                                <input type="checkbox" name="allow_public_api" <?php echo ($G['allow_public_api'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                            <label class="switch" title="Callback">
                                                <input type="checkbox" name="allow_public_callback" <?php echo ($G['allow_public_callback'] ?? 1) ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <hr style="border:0; border-top:1px solid #333; margin: 20px 0;">

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Индивидуальный белый список</strong>
                                            <span>Конкретные ID действий (action) через запятую, если они не подходят под префиксы.</span>
                                        </div>
                                        <div class="control-input" style="width: 250px;">
                                            <input type="text" name="public_actions_whitelist" class="box-input" value="<?php echo h($G['public_actions_whitelist'] ?? ''); ?>" placeholder="custom_id, ping">
                                        </div>
                                    </div>
                                    <div class="mgr-hint">
                                        <b>Как это работает:</b> <br>
                                        1. Наличие <code>cron_token</code> в URL всегда делает запрос публичным. <br>
                                        2. Префиксы выше позволяют обращаться к модулям без входа. <br>
                                        3. В разделе <b>Модули</b> должен быть включен доступ "TG/Webhook" для нужного модуля.
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 3: System -->
                            <div id="tab-system" class="s-pane">
                                <div class="s-group">
                                    <h3>Системное окружение</h3>
                                    
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Временная зона (Timezone)</strong>
                                            <span>Используется для функций `date()` и штампов времени.</span>
                                        </div>
                                        <div class="control-input">
                                            <select name="timezone" class="box-input">
                                                <option value="Europe/Moscow" <?php echo $tz=='Europe/Moscow'?'selected':''; ?>>Москва (UTC+3)</option>
                                                <option value="Europe/Chisinau" <?php echo $tz=='Europe/Chisinau'?'selected':''; ?>>Кишинев (UTC+2)</option>
                                                <option value="Europe/Kiev" <?php echo $tz=='Europe/Kiev'?'selected':''; ?>>Киев (UTC+2)</option>
                                                <option value="UTC" <?php echo $tz=='UTC'?'selected':''; ?>>UTC</option>
                                                <option value="US/Eastern" <?php echo $tz=='US/Eastern'?'selected':''; ?>>New York</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Тайм-аут сессии</strong>
                                            <span>Сколько минут хранить сессию админа без активности.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="number" name="session_timeout" value="<?php echo $sess_time; ?>" class="box-input" style="width: 80px;">
                                            <span style="display:inline-block; font-size:12px; margin-left:5px;">мин</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Настройки темы</h3>

            <div class="control-row">
                <div class="control-label">
                    <strong>Пресет темы</strong>
                    <span>Быстрый выбор стиля интерфейса.</span>
                </div>
                <div class="control-input">
                    <select name="theme_preset" class="box-input">
                        <option value="dark" <?php echo $theme_preset=='dark'?'selected':''; ?>>Dark (Default)</option>
                        <option value="graphite" <?php echo $theme_preset=='graphite'?'selected':''; ?>>Graphite</option>
                        <option value="midnight" <?php echo $theme_preset=='midnight'?'selected':''; ?>>Midnight</option>
                        <option value="contrast" <?php echo $theme_preset=='contrast'?'selected':''; ?>>High Contrast</option>
                        <option value="dracula" <?php echo $theme_preset=='dracula'?'selected':''; ?>>Dracula</option>
                        <option value="monokai" <?php echo $theme_preset=='monokai'?'selected':''; ?>>Monokai</option>
                        <option value="obsidian" <?php echo $theme_preset=='obsidian'?'selected':''; ?>>Obsidian</option>
                        <option value="ocean" <?php echo $theme_preset=='ocean'?'selected':''; ?>>Ocean</option>
                        <option value="cyber" <?php echo $theme_preset=='cyber'?'selected':''; ?>>Cyber</option>
                    </select>
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Стиль фона</strong>
                    <span>Сплошной, градиент или кастомное изображение.</span>
                </div>
                <div class="control-input">
                    <select name="bg_style" class="box-input">
                        <option value="solid" <?php echo $bg_style=='solid'?'selected':''; ?>>Сплошной цвет</option>
                        <option value="gradient" <?php echo $bg_style=='gradient'?'selected':''; ?>>Градиент</option>
                        <option value="image" <?php echo $bg_style=='image'?'selected':''; ?>>Изображение</option>
                    </select>
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Градиент (от/до)</strong>
                    <span>Используется, если выбран «Градиент».</span>
                </div>
                <div class="control-input" style="display:flex; gap:6px; align-items:center;">
                    <input type="color" name="bg_grad_from" value="<?php echo h($bg_grad_from); ?>" style="width:48px; padding:0;">
                    <input type="color" name="bg_grad_to" value="<?php echo h($bg_grad_to); ?>" style="width:48px; padding:0;">
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Фоновое изображение</strong>
                    <span>URL картинки. Работает при стиле «Изображение».</span>
                </div>
                <div class="control-input">
                    <input type="text" name="bg_image" class="box-input" value="<?php echo h($bg_image); ?>" placeholder="https://example.com/bg.jpg">
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Шум/зерно</strong>
                    <span>Добавляет лёгкую текстуру.</span>
                </div>
                <div class="control-input">
                    <label class="switch">
                        <input type="checkbox" name="noise" <?php echo $noise ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <input type="number" name="noise_opacity" value="<?php echo $noise_opacity; ?>" class="box-input" style="width:70px; margin-top:8px;" min="0" max="30"> %
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Стеклянные панели</strong>
                    <span>Лёгкое размытие и прозрачность карточек.</span>
                </div>
                <div class="control-input">
                    <label class="switch">
                        <input type="checkbox" name="glass_panels" <?php echo $glass_panels ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Подсветка кнопок</strong>
                    <span>Неоновое свечение primary‑кнопок.</span>
                </div>
                <div class="control-input">
                    <label class="switch">
                        <input type="checkbox" name="accent_glow" <?php echo $accent_glow ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
            <div class="control-row">
                <div class="control-label">
                    <strong>Скорость анимаций</strong>
                    <span>Настройка длительности hover/transition.</span>
                </div>
                <div class="control-input">
                    <select name="anim_speed" class="box-input">
                        <option value="slow" <?php echo $anim_speed=='slow'?'selected':''; ?>>Медленно</option>
                        <option value="normal" <?php echo $anim_speed=='normal'?'selected':''; ?>>Нормально</option>
                        <option value="fast" <?php echo $anim_speed=='fast'?'selected':''; ?>>Быстро</option>
                    </select>
                </div>
            </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Размер шрифта интерфейса</strong>
                                            <span>От 11 до 18 px.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="number" name="ui_font_size" value="<?php echo $ui_font_size; ?>" class="box-input" min="11" max="18">
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Радиус скругления</strong>
                                            <span>Влияет на карточки и кнопки.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="number" name="ui_radius" value="<?php echo $ui_radius; ?>" class="box-input" min="2" max="14">
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Ширина сайдбара</strong>
                                            <span>От 180 до 320 px.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="number" name="sidebar_width" value="<?php echo $sidebar_width; ?>" class="box-input" min="180" max="320">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Ширина контента</strong>
                                            <span>Максимальная ширина центральной колонки.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="number" name="content_max_width" value="<?php echo $content_max_width; ?>" class="box-input" min="960" max="1600">
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Плотность интерфейса</strong>
                                            <span>Компактный / обычный / просторный.</span>
                                        </div>
                                        <div class="control-input">
                                            <select name="ui_density" class="box-input">
                                                <option value="compact" <?php echo $ui_density=='compact'?'selected':''; ?>>Компактная</option>
                                                <option value="cozy" <?php echo $ui_density=='cozy'?'selected':''; ?>>Обычная</option>
                                                <option value="spacious" <?php echo $ui_density=='spacious'?'selected':''; ?>>Просторная</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Компактный режим</strong>
                                            <span>Уменьшенные кнопки и отступы.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="ui_compact" <?php echo $ui_compact ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Тени карточек</strong>
                                            <span>Визуальная глубина.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="ui_shadow" <?php echo $ui_shadow ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Размытие верхней панели</strong>
                                            <span>Glass‑эффект.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="ui_blur" <?php echo $ui_blur ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Анимации интерфейса</strong>
                                            <span>Включить/отключить плавность.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="ui_animations" <?php echo $ui_animations ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Верхняя панель</strong>
                                            <span>Показать/скрыть topbar.</span>
                                        </div>
                                        <div class="control-input">
                                            <label class="switch">
                                                <input type="checkbox" name="topbar_enabled" <?php echo $topbar_enabled ? 'checked' : ''; ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Палитра (ручная настройка)</h3>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Фон приложения</strong>
                                            <span>Пусто = взять из пресета.</span>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_bg_app" class="box-input" value="<?php echo h($color_bg_app); ?>" placeholder="#1e1e1e">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Фон сайдбара</strong>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_bg_sidebar" class="box-input" value="<?php echo h($color_bg_sidebar); ?>" placeholder="#252526">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Фон панелей</strong>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_bg_panel" class="box-input" value="<?php echo h($color_bg_panel); ?>" placeholder="#2d2d2d">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Основной текст</strong>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_text" class="box-input" value="<?php echo h($color_text); ?>" placeholder="#cccccc">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Вторичный текст</strong>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_text_dim" class="box-input" value="<?php echo h($color_text_dim); ?>" placeholder="#858585">
                                        </div>
                                    </div>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Границы</strong>
                                        </div>
                                        <div class="control-input">
                                            <input type="text" name="color_border" class="box-input" value="<?php echo h($color_border); ?>" placeholder="#3e3e42">
                                        </div>
                                    </div>
                                </div>

                                <div class="s-group">
                                    <h3>Кастомный CSS</h3>
                                    <div class="control-row">
                                        <div class="control-label">
                                            <strong>Дополнительные стили</strong>
                                            <span>Вставляется в конец страницы.</span>
                                        </div>
                                        <div class="control-input" style="width: 420px;">
                                            <textarea name="custom_css" class="code-editor" rows="6" placeholder="/* Ваш CSS */"><?php echo h($custom_css); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 4: Backups -->
                            <?php if (can('backup_download')): ?>
                            <div id="tab-backup" class="s-pane">
                                <div class="s-group">
                                    <h3>Управление данными</h3>
                                    <div class="card" style="border: 1px solid #333; background: #222;">
                                        <div class="card-body">
                                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                                <div>
                                                    <strong style="display:block; font-size:14px; color:var(--text-bright); margin-bottom:5px;">Экспорт всех данных (JSON)</strong>
                                                    <div style="font-size:12px; color:var(--text-dim); max-width:400px;">
                                                        Создает один JSON-файл, содержащий настройки, список модулей и содержимое всех JSON-файлов данных. Полезно для переноса.
                                                    </div>
                                                </div>
                                                <!-- Используем отдельную форму для кнопки бэкапа, чтобы не сабмитить главную форму -->
                                                <button type="button" onclick="document.getElementById('backupForm').submit()" class="btn btn-secondary" style="height: 40px;">
                                                    <svg style="margin-right:8px; vertical-align:middle" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                    Скачать Бэкап
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- TAB 5: Users -->
                            <div id="tab-users" class="s-pane">
                                <div class="s-group">
                                    <h3 class="mgr-title">Управление менеджерами и правами</h3>
                                    <?php
                                        $perm_groups = [
                                            'Разделы' => [
                                                'panel_view' => 'Панель (просмотр)',
                                                'modules_view' => 'Модули (просмотр)',
                                                'js_view' => 'JS плагины (просмотр)',
                                                'settings_view' => 'Настройки (просмотр)'
                                            ],
                                            'Модули: действия' => [
                                                'modules_add' => 'Создавать модули',
                                                'modules_edit_meta' => 'Менять название/ID',
                                                'modules_edit_code' => 'Редактировать код',
                                                'modules_delete' => 'Удалять модули',
                                                'modules_reorder' => 'Менять порядок'
                                            ],
                                            'JS плагины: действия' => [
                                                'js_add' => 'Добавлять',
                                                'js_edit' => 'Редактировать',
                                                'js_delete' => 'Удалять',
                                                'js_reorder' => 'Менять порядок'
                                            ],
                                            'Система' => [
                                                'settings_save' => 'Сохранять настройки',
                                                'backup_download' => 'Скачивать бэкап'
                                            ],
                                            'Администрирование' => [
                                                'managers_manage' => 'Управлять менеджерами'
                                            ]
                                        ];
                                        $module_toggle_list = [];
                                        foreach ($mod_settings as $mid => $m) {
                                            $name = $m['name'] ?? $mid;
                                            $module_toggle_list['module_full_' . $mid] = $name;
                                        }
                                    ?>

                                    <?php if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'manager' && !can('managers_manage')): ?>
                                        <div class="card" style="border:1px solid #333; background:#1b1b1b;">
                                            <div class="card-body" style="font-size:14px; color:var(--text-dim);">
                                                Доступ к управлению менеджерами закрыт. Попросите администратора выдать право <b>«Управлять менеджерами»</b>.
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mgr-hero">
                                            <div>
                                                <div class="mgr-sub">Гибкая система прав: выдавайте доступы на уровне разделов, действий и отдельных модулей.</div>
                                                <div class="mgr-note">Главный админ входит только по паролю и всегда имеет полный доступ.</div>
                                            </div>
                                        </div>

                                        <div class="mgr-card">
                                            <div class="mgr-card-title">Создать менеджера</div>
                                            <div class="mgr-form-row">
                                                <input type="text" id="newMgrId" class="box-input mgr-input" placeholder="Логин (id латин.)">
                                                <input type="text" id="newMgrName" class="box-input mgr-input" placeholder="Имя">
                                                <input type="password" id="newMgrPass" class="box-input mgr-input" placeholder="Пароль">
                                                <button type="button" class="btn btn-primary" onclick="addManager()">Добавить</button>
                                            </div>
                                            <div class="mgr-hint">Логин обязателен: менеджеры входят по логину и паролю. Админ — только по паролю.</div>
                                        </div>

                                        <div id="mgrList" class="mgr-list">
                                            <?php if(empty($managers)): ?>
                                                <div class="mgr-empty">Менеджеры не созданы</div>
                                            <?php else: foreach($managers as $mid=>$m): ?>
                                                <?php $perms = normalize_manager_perms($m['perms'] ?? []); ?>
                                                <div class="mgr-item" data-mid="<?php echo h($mid); ?>">
                                                    <div class="mgr-head">
                                                        <div>
                                                            <div class="mgr-name"><?php echo h($m['name'] ?? $mid); ?></div>
                                                            <div class="mgr-login">Логин: <code><?php echo h($mid); ?></code></div>
                                                            <input type="hidden" data-field="id" value="<?php echo h($mid); ?>">
                                                        </div>
                                                        <div class="mgr-actions">
                                                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms('<?php echo h($mid); ?>', true)">Все права</button>
                                                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms('<?php echo h($mid); ?>', false)">Снять</button>
                                                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteManager('<?php echo h($mid); ?>')">Удалить</button>
                                                        </div>
                                                    </div>

                                                    <div class="mgr-fields">
                                                        <div>
                                                            <label class="mgr-label">Имя менеджера</label>
                                                            <input type="text" class="box-input mgr-input" data-field="name" value="<?php echo h($m['name'] ?? $mid); ?>">
                                                        </div>
                                                        <div>
                                                            <label class="mgr-label">Новый пароль</label>
                                                            <input type="password" class="box-input mgr-input" data-field="pass_new" placeholder="Оставьте пустым, чтобы не менять">
                                                            <input type="hidden" data-field="pass_current" value="<?php echo h($m['password'] ?? ''); ?>">
                                                        </div>
                                                    </div>

                                                    <div class="mgr-modules">
                                                        <?php $allModulesChecked = !empty($perms['modules_full_access']) ? 'checked' : ''; ?>
                                                        <div class="mgr-modules-head">
                                                            <label>
                                                                <input type="checkbox" data-mgrid="<?php echo h($mid); ?>" data-module-master="1" data-perm="modules_full_access" <?php echo $allModulesChecked; ?>>
                                                                Полный доступ ко всем модулям
                                                            </label>
                                                        </div>
                                                        <?php if (!empty($module_toggle_list)): ?>
                                                            <div class="mgr-modules-list">
                                                                <?php foreach($module_toggle_list as $key => $label): ?>
                                                                    <?php
                                                                        $moduleId = substr((string) $key, strlen('module_full_'));
                                                                        $checked = (!empty($perms[$key]) || !empty($perms['module_' . $moduleId])) ? 'checked' : '';
                                                                    ?>
                                                                    <label>
                                                                        <input type="checkbox" data-mgrid="<?php echo h($mid); ?>" data-module-toggle="1" data-perm="<?php echo h($key); ?>" <?php echo $checked; ?>>
                                                                        <?php echo h($label); ?>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <div class="mgr-hint">Включите отдельные модули, если нужен не весь доступ.</div>
                                                        <?php else: ?>
                                                            <div class="mgr-hint">Модулей пока нет.</div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <details class="mgr-advanced">
                                                        <summary>Расширенные права</summary>
                                                        <div class="mgr-perms">
                                                            <?php foreach($perm_groups as $gtitle => $plist): ?>
                                                                <div class="mgr-perm-group">
                                                                    <h5><?php echo h($gtitle); ?></h5>
                                                                    <?php foreach($plist as $key => $label): ?>
                                                                        <?php $checked = !empty($perms[$key]) ? 'checked' : ''; ?>
                                                                        <label>
                                                                            <input type="checkbox" data-mgrid="<?php echo h($mid); ?>" data-perm="<?php echo h($key); ?>" <?php echo $checked; ?>>
                                                                            <?php echo h($label); ?>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </details>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </div>

                                        <div class="mgr-footer">
                                            <button type="button" class="btn btn-primary" onclick="saveManagers()">Сохранить права</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </form>
                    
                    <!-- Скрытая форма для бэкапа -->
                    <form id="backupForm" method="POST" style="display:none;">
                         <input type="hidden" name="action" value="download_backup">
                    </form>

                    <script>
                        // Logic: Tab Switching
                        function switchTab(tabId, tabEl) {
                            document.querySelectorAll('.s-pane').forEach(el => el.classList.remove('active'));
                            document.querySelectorAll('.s-tab').forEach(el => el.classList.remove('active'));
                            document.getElementById(tabId).classList.add('active');
                            tabEl.classList.add('active');
                        }

                        // Logic: Color Sync
                        const cp = document.getElementById('accentPicker');
                        const ct = document.getElementById('accentText');
                        if(cp && ct) {
                            cp.addEventListener('input', e => ct.value = e.target.value);
                            ct.addEventListener('input', e => cp.value = e.target.value);
                        }

                        // Managers logic
                        const permGroups = <?php echo json_encode($perm_groups, JSON_UNESCAPED_UNICODE); ?>;
                        const moduleTogglePerms = <?php echo json_encode($module_toggle_list, JSON_UNESCAPED_UNICODE); ?>;

                        function renderPermGroups(id, defaultPerms) {
                            let html = '';
                            Object.keys(permGroups).forEach(group => {
                                html += `<div class="mgr-perm-group"><h5>${group}</h5>`;
                                const items = permGroups[group];
                                Object.keys(items).forEach(key => {
                                    const checked = defaultPerms && defaultPerms[key] ? 'checked' : '';
                                    html += `<label><input type="checkbox" data-mgrid="${id}" data-perm="${key}" ${checked}>${items[key]}</label>`;
                                });
                                html += `</div>`;
                            });
                            return html;
                        }

                        function renderModuleToggles(id, defaultPerms) {
                            const allChecked = defaultPerms && defaultPerms['modules_full_access'] ? 'checked' : '';
                            let html = `
                                <div class="mgr-modules">
                                    <div class="mgr-modules-head">
                                        <label>
                                            <input type="checkbox" data-mgrid="${id}" data-module-master="1" data-perm="modules_full_access" ${allChecked}>
                                            Полный доступ ко всем модулям
                                        </label>
                                    </div>
                            `;
                            const keys = Object.keys(moduleTogglePerms || {});
                            if (keys.length) {
                                html += `<div class="mgr-modules-list">`;
                                keys.forEach(key => {
                                    const mid = key.replace(/^module_full_/, '');
                                    const checked = defaultPerms && (defaultPerms[key] || defaultPerms['module_' + mid]) ? 'checked' : '';
                                    html += `<label><input type="checkbox" data-mgrid="${id}" data-module-toggle="1" data-perm="${key}" ${checked}>${moduleTogglePerms[key]}</label>`;
                                });
                                html += `</div><div class="mgr-hint">Включите отдельные модули, если нужен не весь доступ.</div>`;
                            } else {
                                html += `<div class="mgr-hint">Модулей пока нет.</div>`;
                            }
                            html += `</div>`;
                            return html;
                        }

                        function syncModuleToggleState(root) {
                            if (!root) return;
                            const master = root.querySelector('input[data-module-master="1"]');
                            if (!master) return;
                            root.querySelectorAll('input[data-module-toggle="1"]').forEach(cb => {
                                cb.disabled = master.checked;
                                if (master.checked) cb.checked = false;
                            });
                        }

                        function syncAllModuleToggles() {
                            document.querySelectorAll('#mgrList .mgr-item').forEach(syncModuleToggleState);
                        }

                        function addManager() {
                            const id = document.getElementById('newMgrId').value.trim().toLowerCase();
                            const name = document.getElementById('newMgrName').value.trim();
                            const pass = document.getElementById('newMgrPass').value.trim();
                            if(!id || !name || !pass) { alert('Заполните логин, имя и пароль'); return; }
                            if (!/^[a-z0-9-]+$/.test(id)) { alert('Логин должен быть латиницей, цифрами или дефисом'); return; }

                            const list = document.getElementById('mgrList');
                            const div = document.createElement('div');
                            div.className = 'mgr-item';
                            div.setAttribute('data-mid', id);

                            const defaultPerms = { panel_view: 1, modules_view: 1, js_view: 1 };
                            div.innerHTML = `
                                <div class="mgr-head">
                                    <div>
                                        <div class="mgr-name">${name}</div>
                                        <div class="mgr-login">Логин: <code>${id}</code></div>
                                        <input type="hidden" data-field="id" value="${id}">
                                    </div>
                                    <div class="mgr-actions">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms('${id}', true)">Все права</button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms('${id}', false)">Снять</button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.mgr-item').remove()">Удалить</button>
                                    </div>
                                </div>
                                <div class="mgr-fields">
                                    <div>
                                        <label class="mgr-label">Имя менеджера</label>
                                        <input type="text" class="box-input mgr-input" data-field="name" value="${name}">
                                    </div>
                                    <div>
                                        <label class="mgr-label">Новый пароль</label>
                                        <input type="password" class="box-input mgr-input" data-field="pass_new" placeholder="Оставьте пустым, чтобы не менять">
                                        <input type="hidden" data-field="pass_current" value="${pass}">
                                    </div>
                                </div>
                                ${renderModuleToggles(id, defaultPerms)}
                                <details class="mgr-advanced">
                                    <summary>Расширенные права</summary>
                                    <div class="mgr-perms">
                                        ${renderPermGroups(id, defaultPerms)}
                                    </div>
                                </details>
                            `;
                            list.appendChild(div);
                            syncModuleToggleState(div);
                            document.getElementById('newMgrId').value='';
                            document.getElementById('newMgrName').value='';
                            document.getElementById('newMgrPass').value='';
                        }

                        function deleteManager(id) {
                            const fd = new FormData();
                            fd.append('action','save_managers');
                            fd.append('delete', id);
                            fetch('',{method:'POST',body:fd}).then(()=>location.reload());
                        }

                        function saveManagers() {
                            const fd = new FormData();
                            fd.append('action','save_managers');
                            const blocks = document.querySelectorAll('#mgrList .mgr-item');
                            let data = {};
                            blocks.forEach(b => {
                                let idInput = b.querySelector('[data-field="id"]');
                                let nameInput = b.querySelector('[data-field="name"]');
                                let passNew = b.querySelector('[data-field="pass_new"]');
                                let passCurrent = b.querySelector('[data-field="pass_current"]');
                                const mid = idInput ? idInput.value : b.getAttribute('data-mid') || '';
                                if(!mid) return;
                                const perms = {};
                                b.querySelectorAll('input[type=checkbox][data-perm]').forEach(cb => {
                                    perms[cb.getAttribute('data-perm')] = cb.checked ? 1 : 0;
                                });
                                const newPass = passNew ? passNew.value.trim() : '';
                                const curPass = passCurrent ? passCurrent.value : '';
                                data[mid] = {
                                    name: nameInput ? nameInput.value : mid,
                                    password: newPass ? newPass : curPass,
                                    perms: perms
                                };
                            });
                            fd.append('managers_json', JSON.stringify(data));
                            fetch('', {method:'POST', body: fd})
                                .then(r=>r.json())
                                .then(res=>{
                                    if(res.status==='ok') location.reload();
                                    else alert('Ошибка сохранения менеджеров');
                                })
                                .catch(()=>alert('Сеть недоступна'));
                        }

                        function toggleAllPerms(mid, state) {
                            document.querySelectorAll(`input[data-mgrid="${mid}"][data-perm]`).forEach(cb => {
                                cb.checked = !!state;
                            });
                            const root = document.querySelector(`#mgrList .mgr-item[data-mid="${mid}"]`);
                            syncModuleToggleState(root);
                        }

                        document.addEventListener('change', function (e) {
                            const target = e.target;
                            if (!target || !target.matches('input[data-module-master="1"]')) return;
                            const root = target.closest('.mgr-item');
                            syncModuleToggleState(root);
                        });

                        syncAllModuleToggles();
                    </script>

                <?php break;
            }
        }
        ?>
    </div>
</main>

<script>
    (function () {
        const input = document.getElementById('moduleSearch');
        const items = Array.from(document.querySelectorAll('.nav-section a.nav-item[data-module-id]'));
        const count = document.getElementById('moduleCount');
        if (!input || !count) return;

        const updateCount = (visible) => {
            count.textContent = items.length ? `Модулей: ${visible} / ${items.length}` : 'Модулей: 0';
        };

        const filter = () => {
            const q = input.value.trim().toLowerCase();
            let visible = 0;
            items.forEach(item => {
                const name = item.getAttribute('data-module-name') || '';
                const id = item.getAttribute('data-module-id') || '';
                const ok = !q || name.includes(q) || id.includes(q);
                item.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });
            updateCount(visible);
        };

        input.addEventListener('input', filter);
        filter();

        // Collapse toggle
        const wrap = document.getElementById('modulesWrap');
        const toggle = document.getElementById('modulesToggle');
        if (wrap && toggle) {
            const defaultState = <?php echo $menu_modules_collapsed ? 'true' : 'false'; ?>;
            const stored = localStorage.getItem('cc_nav_mods_collapsed');
            let collapsed = stored === null ? defaultState : stored === '1';
            const applyState = () => {
                wrap.classList.toggle('collapsed', collapsed);
                toggle.textContent = collapsed ? '▶' : '▼';
            };
            applyState();
            toggle.addEventListener('click', () => {
                collapsed = !collapsed;
                localStorage.setItem('cc_nav_mods_collapsed', collapsed ? '1' : '0');
                applyState();
            });
        }
    })();

    (function () {
        const quickJump = document.getElementById('quickJump');
        if (!quickJump) return;
        const quickMap = {
            "Панель": "<?php echo ADMIN_FILE; ?>?page=panel",
            "Модули": "<?php echo ADMIN_FILE; ?>?page=modules",
            "JS Плагины": "<?php echo ADMIN_FILE; ?>?page=js",
            "Настройки": "<?php echo ADMIN_FILE; ?>?page=settings"
            <?php foreach($mod_settings as $mid => $m): ?>
            , "<?php echo addslashes($m['name']); ?>": "<?php echo ADMIN_FILE; ?>?module=<?php echo $mid; ?>"
            <?php endforeach; ?>
        };

        const go = () => {
            const val = quickJump.value.trim();
            if (quickMap[val]) window.location.href = quickMap[val];
        };
        quickJump.addEventListener('change', go);
        quickJump.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); go(); }
            if (e.key === '/') { e.preventDefault(); }
        });
        window.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement !== quickJump) {
                e.preventDefault();
                quickJump.focus();
            }
        });
    })();

    (function () {
        const items = Array.from(document.querySelectorAll('.nav-section a.nav-item[data-module-id]'));
        if (!items.length) return;
        const pinnedSection = document.getElementById('pinnedSection');
        const pinnedList = document.getElementById('pinnedList');
        const recentList = document.getElementById('recentList');
        const recentLabel = document.getElementById('recentLabel');

        const showRecent = <?php echo $show_recent ? 'true' : 'false'; ?>;
        if (!showRecent) {
            if (recentLabel) recentLabel.style.display = 'none';
            if (recentList) recentList.style.display = 'none';
        }

        const getPins = () => {
            try { return JSON.parse(localStorage.getItem('cc_admin_pins') || '[]'); } catch { return []; }
        };
        const setPins = (arr) => localStorage.setItem('cc_admin_pins', JSON.stringify(arr));
        const getRecent = () => {
            try { return JSON.parse(localStorage.getItem('cc_admin_recent') || '[]'); } catch { return []; }
        };
        const setRecent = (arr) => localStorage.setItem('cc_admin_recent', JSON.stringify(arr));

        const cloneItem = (item) => {
            const a = item.cloneNode(true);
            a.classList.remove('active');
            return a;
        };

        const renderPins = () => {
            const pins = getPins();
            pinnedList.innerHTML = '';
            if (!pins.length) {
                pinnedSection.style.display = 'none';
                return;
            }
            pinnedSection.style.display = '';
            pins.forEach(id => {
                const src = items.find(i => i.getAttribute('data-module-id') === id);
                if (src) pinnedList.appendChild(cloneItem(src));
            });
        };

        const renderRecent = () => {
            const recent = getRecent();
            recentList.innerHTML = '';
            if (!recent.length || !showRecent) {
                if (recentLabel) recentLabel.style.display = 'none';
                return;
            }
            if (recentLabel) recentLabel.style.display = '';
            recent.forEach(id => {
                const src = items.find(i => i.getAttribute('data-module-id') === id);
                if (src) recentList.appendChild(cloneItem(src));
            });
        };

        items.forEach(item => {
            const id = item.getAttribute('data-module-id');
            const star = item.querySelector('.nav-star');
            if (star) {
                star.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const pins = getPins();
                    const idx = pins.indexOf(id);
                    if (idx >= 0) pins.splice(idx, 1);
                    else pins.unshift(id);
                    setPins(pins.slice(0, 20));
                    star.classList.toggle('active', idx < 0);
                    renderPins();
                });
            }
            item.addEventListener('click', () => {
                const recent = getRecent().filter(x => x !== id);
                recent.unshift(id);
                setRecent(recent.slice(0, 6));
                renderRecent();
            });
        });

        // Init stars
        const pins = getPins();
        items.forEach(item => {
            const id = item.getAttribute('data-module-id');
            const star = item.querySelector('.nav-star');
            if (star && pins.includes(id)) star.classList.add('active');
        });
        renderPins();
        renderRecent();
    })();

    function toggleSidebar() {
        const sb = document.getElementById('sidebar');
        if (!sb) return;
        sb.classList.toggle('open');
    }
</script>

<?php if (!empty($custom_css)): ?>
<style>
<?php echo $custom_css; ?>
</style>
<?php endif; ?>

</body>
</html>
<?php ob_end_flush(); ?>
