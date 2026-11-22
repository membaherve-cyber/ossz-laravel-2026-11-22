<?php
/**
 * OSSZ deployment runner v4 — 2026-11-22.
 * Key-protected. DELETE after deployment completes.
 */
$KEY = 'OsszDeploy2026x8kQ4vZt7Wn2R';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); die('Forbidden'); }
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(900);
header('Content-Type: application/json');

$home   = '/home/osszakze';
$root   = $home . '/public_html';
$backup = $home . '/public_html-backup-20261122';
$php    = '/opt/alt/php82/usr/bin/php';
$repoZip = $home . '/_repo.zip';
$repoUrl = ($_GET['url'] ?? 'https://codeload.github.com/membaherve-cyber/ossz-laravel-2026-11-22/zip/refs/heads/main');
$action = $_GET['action'] ?? '';

function run(string $cmd): string {
    $out = [];
    exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}
function env_read(string $path): array {
    $res = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        $res[$k] = $v;
    }
    return $res;
}
function j($arr) { echo json_encode($arr); exit; }

function backup_missing_files(string $backup, string $root, array $dirs): array {
    $missing = [];
    foreach ($dirs as $dir) {
        $base = $backup . '/' . $dir;
        if (!is_dir($base)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($backup) + 1);
            if (strpos($rel, '/.git') !== false || strpos($rel, 'node_modules') !== false) continue;
            if (!file_exists($root . '/' . $rel)) $missing[] = $rel;
        }
    }
    return $missing;
}

switch ($action) {

    case 'info': {
        j([
            'php' => phpversion(),
            'docroot_visible' => is_dir($root),
            'backup_visible' => is_dir($backup),
            'backup_vendor' => is_dir($backup . '/vendor'),
            'backup_env' => file_exists($backup . '/.env'),
            'zip_ext' => class_exists('ZipArchive') ? 'yes' : 'no',
        ]);
    }

    case 'pull': {
        $data = @file_get_contents($repoUrl);
        if ($data === false || strlen($data) < 1000000) {
            run("cd $home && /usr/bin/curl -sL -o _repo.zip " . escapeshellarg($repoUrl));
        } else {
            file_put_contents($repoZip, $data);
        }
        $size = @filesize($repoZip);
        j(['status' => $size > 1000000 ? 'ok' : 'error', 'size' => $size]);
    }

    case 'unzip': {
        if (!class_exists('ZipArchive')) j(['status' => 'error', 'msg' => 'no ZipArchive']);
        if (!file_exists($repoZip)) j(['status' => 'error', 'msg' => 'zip missing']);
        $extract = $home . '/_repo-extract';
        run("rm -rf $extract && mkdir -p $extract");
        $zip = new ZipArchive;
        if ($zip->open($repoZip) !== TRUE) j(['status' => 'error', 'msg' => 'zip open failed']);
        $zip->extractTo($extract);
        $zip->close();
        $dirs = glob($extract . '/*', GLOB_ONLYDIR);
        if (count($dirs) !== 1) j(['status' => 'error', 'msg' => 'unexpected archive layout']);
        $src = $dirs[0];
        $moved = 0;
        foreach (glob($src . '/*') as $item) {
            $name = basename($item);
            run("rm -rf " . escapeshellarg("$root/$name"));
            if (rename($item, "$root/$name")) $moved++;
        }
        foreach (glob($src . '/.*') as $item) {
            $name = basename($item);
            if (in_array($name, ['.', '..'])) continue;
            @copy($item, "$root/$name");
        }
        j([
            'status' => 'ok', 'moved' => $moved,
            'artisan' => file_exists($root . '/artisan'),
            'public_catalogue' => count(glob($root . '/public/catalogue/*') ?: []),
        ]);
    }

    case 'wire': {
        $res = [];
        foreach (['vendor', '.env'] as $item) {
            $from = "$backup/$item";
            $to   = "$root/$item";
            if (file_exists($from) && !file_exists($to)) {
                $res[$item] = rename($from, $to) ? 'moved' : 'move-failed';
            } else {
                $res[$item] = file_exists($to) ? 'already-present' : 'missing-in-backup';
            }
        }
        run("mkdir -p $root/storage/framework/views $root/storage/framework/sessions $root/storage/framework/cache $root/storage/logs $root/storage/app/public $root/bootstrap/cache");
        run("chmod -R u+rwX $root/storage $root/bootstrap/cache");
        if (file_exists($backup . '/.htaccess')) {
            copy($backup . '/.htaccess', $root . '/.htaccess');
            $res['htaccess'] = 'copied-from-backup';
        } else {
            file_put_contents($root . '/.htaccess', "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^(.*)$ public/\$1 [L]\n</IfModule>\n");
            $res['htaccess'] = 'fallback-created';
        }
        j($res);
    }

    case 'diff-files': {
        $differing = [];
        foreach (['app', 'resources', 'routes', 'config'] as $dir) {
            $base = $backup . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen($backup) + 1);
                if (strpos($rel, '/.git') !== false || strpos($rel, 'node_modules') !== false) continue;
                $cur = $root . '/' . $rel;
                if (!file_exists($cur)) { $differing[] = $rel . ' (missing)'; continue; }
                if (md5_file($f->getPathname()) !== md5_file($cur)) $differing[] = $rel . ' (differs)';
            }
        }
        j(['differing' => $differing]);
    }

    case 'take-backup': {
        // Prefer the known-working deployed versions, EXCEPT files we fixed intentionally.
        $keepOurs = ['app/Support/Auth.php'];
        $differing = [];
        foreach (['app', 'resources', 'routes', 'config'] as $dir) {
            $base = $backup . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = substr($f->getPathname(), strlen($backup) + 1);
                if (strpos($rel, '/.git') !== false || strpos($rel, 'node_modules') !== false) continue;
                if (in_array($rel, $keepOurs, true)) continue;
                if (str_starts_with($rel, 'app/Models/')) continue; // keep our timestamp fixes
                $cur = $root . '/' . $rel;
                if (file_exists($cur) && md5_file($f->getPathname()) !== md5_file($cur)) {
                    if (copy($f->getPathname(), $cur)) $differing[] = $rel;
                }
            }
        }
        j(['taken_from_backup' => $differing]);
    }

    case 'export': {
        $zipFile = $root . '/public/_export.zip';
        @unlink($zipFile);
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) j(['status' => 'error', 'msg' => 'zip create failed']);
        $dirs = ['app', 'resources', 'routes', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'public'];
        foreach ($dirs as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isDir()) continue;
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if (strpos($rel, 'public/uploads/') !== false) continue;
                if (strpos($rel, 'public/storage') === 0) continue;
                $zip->addFile($f->getPathname(), $rel);
            }
        }
        foreach (['artisan', 'composer.json', 'composer.lock', '.env.example', '.gitignore', 'package.json'] as $f) {
            if (file_exists($root . '/' . $f)) $zip->addFile($root . '/' . $f, $f);
        }
        $zip->close();
        j(['status' => 'ok', 'size' => filesize($zipFile)]);
    }

    case 'diff-backup': {
        j(['missing' => backup_missing_files($backup, $root, ['app', 'resources', 'routes', 'config', 'database', 'public'])]);
    }

    case 'restore-missing': {
        $restored = [];
        $dirs = ['app', 'resources'];
        $candidates = array_merge(
            backup_missing_files($backup, $root, $dirs),
            in_array('public/ossz-logo.jpeg', backup_missing_files($backup, $root, ['public']), true) ? ['public/ossz-logo.jpeg'] : []
        );
        foreach ($candidates as $rel) {
            $target = $root . '/' . $rel;
            $dirTarget = dirname($target);
            if (!is_dir($dirTarget)) mkdir($dirTarget, 0755, true);
            if (copy($backup . '/' . $rel, $target)) $restored[] = $rel;
        }
        j(['restored' => $restored]);
    }

    case 'backup-db': {
        $env = env_read($root . '/.env');
        $target = $home . '/db-backup-20261122.sql.gz';
        $cmd = "mysqldump --single-transaction -h127.0.0.1 -u" . escapeshellarg($env['DB_USERNAME']) . " -p" . escapeshellarg($env['DB_PASSWORD']) . " " . escapeshellarg($env['DB_DATABASE']) . " | gzip > $target";
        run($cmd);
        j(['status' => 'ok', 'size' => @filesize($target)]);
    }

    case 'counts': {
        $env = env_read($root . '/.env');
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
        $res = [];
        foreach (['products', 'orders', 'users', 'collections', 'contact_messages', 'appointments', 'journal_posts'] as $t) {
            try { $res[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
            catch (Throwable $e) { $res[$t] = 'missing'; }
        }
        j($res);
    }

    case 'migrate': {
        $m  = run("cd $root && $php artisan migrate:fresh --seed --force");
        $c  = run("cd $root && $php artisan optimize:clear");
        $l  = run("cd $root && $php artisan storage:link");
        j(['status' => 'ok', 'migrate' => $m, 'clear' => $c, 'link' => $l]);
    }

    case 'postcheck': {
        $env = env_read($root . '/.env');
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
        $res = [
            'products' => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'product_images' => (int) $pdo->query("SELECT COUNT(*) FROM product_images")->fetchColumn(),
            'product_variants' => (int) $pdo->query("SELECT COUNT(*) FROM product_variants")->fetchColumn(),
            'collections' => (int) $pdo->query("SELECT COUNT(*) FROM collections")->fetchColumn(),
            'journal' => (int) $pdo->query("SELECT COUNT(*) FROM journal_posts")->fetchColumn(),
            'users' => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'first_products' => $pdo->query("SELECT name, base_price FROM products ORDER BY popularity DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC),
        ];
        j($res);
    }

    case 'cleanup': {
        $deleted = [];
        foreach ([$repoZip, $home . '/_repo-extract', $root . '/_deploy.zip', $root . '/public/_deploy_runner.php', __FILE__] as $f) {
            if (file_exists($f)) { $deleted[] = basename($f); run("rm -rf " . escapeshellarg($f)); }
        }
        j(['status' => 'ok', 'deleted' => $deleted]);
    }

    default:
        j(['status' => 'error', 'msg' => 'unknown action']);
}
