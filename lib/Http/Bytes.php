<?php
declare(strict_types=1);
namespace OCA\Chronicle\Http;
use OCP\AppFramework\Http\Response;
class Bytes extends Response {
 public function __construct(private string $body,string $type,int $status=200,array $headers=[]) {parent::__construct($status,$headers);$this->addHeader('Content-Type',$type);}
 public function render():string {return $this->body;}
}
