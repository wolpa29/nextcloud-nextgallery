<?php
declare(strict_types=1);

namespace OCA\Nextgallery;

use OCP\Capabilities\ICapability;

/**
 * Declares NextGallery capabilities according to Nextcloud ICapability interface.
 * Exposes honest feature scope: search, people, labels, snapshots, corrections.
 * Explicitly states unsupported capabilities (e.g. video face tracks, individual pet recognition).
 */
class Capabilities implements ICapability {
    public function getCapabilities(): array {
        return [
            'nextgallery' => [
                'api_version' => 'v1',
                'search' => true,
                'people' => true,
                'labels' => true,
                'snapshots' => true,
                'features' => [
                    'search' => true,
                    'people' => true,
                    'labels' => true,
                    'snapshots' => true,
                    'corrections' => true,
                    'video_faces' => false,
                    'pet_recognition' => false,
                ],
            ],
        ];
    }
}
