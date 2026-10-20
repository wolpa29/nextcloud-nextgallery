<?php
declare(strict_types=1);
$source = dirname(__DIR__) . '/lib/Service/NativePeopleSnapshots.php';
require is_file($source) ? $source : '/var/www/html/custom_apps/nextgallery/lib/Service/NativePeopleSnapshots.php';
use OCA\Nextgallery\Service\NativePeopleSnapshots;
$base = sys_get_temp_dir() . '/native-people-expiry-' . bin2hex(random_bytes(6));
$root = $base . '/native-people-snapshots';
$a = $root . '/' . hash('sha256', 'synthetic-a'); $b = $root . '/' . hash('sha256', 'synthetic-b');
mkdir($a, 0700, true); mkdir($b, 0700, true);
$old = $a . '/' . str_repeat('a', 32) . '.json'; $fresh = $a . '/' . str_repeat('b', 32) . '.json'; $other = $b . '/' . str_repeat('c', 32) . '.json';
file_put_contents($old, '{}'); touch($old, time()-4000); file_put_contents($fresh, '{}'); file_put_contents($other, '{}');
function verify(bool $ok): void { if (!$ok) throw new RuntimeException('Snapshot cleanup contract failed'); }
try {
    verify(NativePeopleSnapshots::cleanup($base) === 1); verify(!file_exists($old)); verify(is_file($fresh) && is_file($other));
    verify(NativePeopleSnapshots::cleanup($base, 'synthetic-a') === 1); verify(!file_exists($fresh) && is_file($other));
    $target = $base . '/protected'; file_put_contents($target, 'keep'); $link = $root . '/' . hash('sha256', 'synthetic-c'); symlink($base, $link);
    NativePeopleSnapshots::cleanup($base, 'synthetic-c'); verify(file_get_contents($target) === 'keep'); unlink($link); unlink($target);
    echo "Native People snapshot cleanup: 6 assertions passed\n";
} finally { @unlink($old); @unlink($fresh); @unlink($other); @rmdir($a); @rmdir($b); @rmdir($root); @rmdir($base); }
