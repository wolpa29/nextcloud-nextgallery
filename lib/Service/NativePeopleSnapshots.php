<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;

/** Bounded derived download files; never follow links or touch canonical face data. */
final class NativePeopleSnapshots {
    public static function cleanup(string $stateDirectory, ?string $uid = null): int {
        $root = rtrim($stateDirectory, '/') . '/native-people-snapshots';
        if (!is_dir($root) || is_link($root)) return 0;
        $directories = $uid === null ? (glob($root . '/*', GLOB_ONLYDIR) ?: []) : [$root . '/' . hash('sha256', $uid)];
        $removed = 0;
        foreach ($directories as $directory) {
            if (is_link($directory) || !is_dir($directory) || !preg_match('/^[a-f0-9]{64}$/D', basename($directory))) continue;
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (!preg_match('/^[a-f0-9]{32}\.(?:json|tmp)$/D', basename($file))) continue;
                if ($uid !== null || (int)@filemtime($file) < time() - 3600) {
                    if ((is_file($file) || is_link($file)) && @unlink($file)) $removed++;
                }
            }
            @rmdir($directory);
        }
        return $removed;
    }
}
