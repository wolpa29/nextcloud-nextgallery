<?php
declare(strict_types=1);
namespace OCA\Nextgallery\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Nextgallery\AI\Service\Faces;
use OCA\Nextgallery\Service\Library;

class FaceCleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private Faces $faces,
        private Library $library,
        private ?\Psr\Log\LoggerInterface $logger = null
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $deadline = microtime(true) + 30;
        $users = array_values(array_unique(array_merge($this->library->users(), $this->faces->accounts())));
        foreach ($users as $uid) {
            try {
                $this->faces->cleanup($uid);
            } catch (\Throwable $e) {
                $this->logger?->warning('NextGallery face cleanup failed for user: ' . $uid, [
                    'app' => 'nextgallery',
                    'exception' => $e,
                ]);
            }
            if (microtime(true) > $deadline) {
                break;
            }
        }
    }
}
