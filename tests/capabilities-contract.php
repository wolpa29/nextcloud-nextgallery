<?php
declare(strict_types=1);

if (is_file('/var/www/html/lib/base.php')) {
    require_once '/var/www/html/lib/base.php';
} elseif (is_file(dirname(__DIR__, 3) . '/lib/base.php')) {
    require_once dirname(__DIR__, 3) . '/lib/base.php';
} else {
    if (!interface_exists('OCP\Capabilities\ICapability')) {
        eval('namespace OCP\Capabilities { interface ICapability { public function getCapabilities(); } }');
    }
}

$source = dirname(__DIR__) . '/lib/Capabilities.php';
require_once is_file($source) ? $source : '/var/www/html/custom_apps/nextgallery/lib/Capabilities.php';

use OCA\Nextgallery\Capabilities;
use OCP\Capabilities\ICapability;

$checks = 0;
function capabilityCheck(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

// 1. Verify class implements ICapability
$capabilities = new Capabilities();
capabilityCheck($capabilities instanceof ICapability, 'Capabilities must implement \OCP\Capabilities\ICapability');

// 2. Verify getCapabilities structure
$caps = $capabilities->getCapabilities();
capabilityCheck(is_array($caps), 'getCapabilities must return an array');
capabilityCheck(isset($caps['nextgallery']) && is_array($caps['nextgallery']), 'Capabilities must contain "nextgallery" array');

$ng = $caps['nextgallery'];
capabilityCheck($ng['api_version'] === 'v1', 'api_version must be v1');
capabilityCheck($ng['search'] === true, 'search must be true');
capabilityCheck($ng['people'] === true, 'people must be true');
capabilityCheck($ng['labels'] === true, 'labels must be true');
capabilityCheck($ng['snapshots'] === true, 'snapshots must be true');

// 3. Verify honest feature scope
capabilityCheck(isset($ng['features']) && is_array($ng['features']), 'features must be an array');
$features = $ng['features'];
capabilityCheck($features['search'] === true, 'feature search must be true');
capabilityCheck($features['people'] === true, 'feature people must be true');
capabilityCheck($features['labels'] === true, 'feature labels must be true');
capabilityCheck($features['snapshots'] === true, 'feature snapshots must be true');
capabilityCheck($features['corrections'] === true, 'feature corrections must be true');
capabilityCheck($features['video_faces'] === false, 'feature video_faces must be false (honest scope)');
capabilityCheck($features['pet_recognition'] === false, 'feature pet_recognition must be false (honest scope)');

// 4. Registration verification in Application
$appSource = dirname(__DIR__) . '/lib/AppInfo/Application.php';
$appCode = file_get_contents(is_file($appSource) ? $appSource : '/var/www/html/custom_apps/nextgallery/lib/AppInfo/Application.php');
capabilityCheck(str_contains($appCode, 'registerCapability'), 'Application must call registerCapability');
capabilityCheck(str_contains($appCode, 'Capabilities::class'), 'Application must register Capabilities::class');

echo "$checks NextGallery capabilities assertions passed successfully!\n";
