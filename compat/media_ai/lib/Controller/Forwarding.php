<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\AppFramework\Http\DataResponse;
trait Forwarding {
 private function unavailable():?DataResponse {
  if (!$this->apps->isEnabledForUser('chronicle')) return new DataResponse(['error'=>'NextGallery Server must be enabled.'],503);
  return null;
 }
 private function delegate(string $name):object {return \OCP\Server::get('OCA\\Chronicle\\Controller\\Ai'.$name.'Controller');}
}
