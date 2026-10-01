<?php

require __DIR__.'/../vendor/autoload.php';

// Класс лежит в src/CensorText/CensorText.php, а PSR-4 ищет его в src/CensorText.php.
require_once __DIR__.'/../src/CensorText/CensorText.php';

error_reporting(E_ALL);

// Фреймворк разрешает пути `~/...` от DOCUMENT_ROOT (Main::preparePath): кэш, логи,
// языковые файлы и шаблоны. Корень — временная папка процесса, чтобы прогон ничего
// не писал в репозиторий и параллельные процессы не делили состояние.
$root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/st-tests-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($root, 0777, true);

$_SERVER['DOCUMENT_ROOT'] = $root;
define('ST_TESTS_ROOT', $root);

\ST_system\Access::setConfig(['salt' => 'st-tests-salt']);

register_shutdown_function(static function () use ($root) {
    \ST_system\Tests\TestCase::removeDir($root);
});
