<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\{IRequest};
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\{DataResponse,StreamResponse};
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired,PublicPage};
/** Forward legacy addresses with the same route arguments and authorization attributes. */
class AdminController extends Controller {
 use Forwarding;
 public function __construct(IRequest $request,private IAppManager $apps) {parent::__construct('media_ai',$request);}

 #[NoAdminRequired]
 public function config(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->config();
 }

 #[NoAdminRequired]
 public function save(array $config): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->save($config);
 }

 #[NoAdminRequired]
 public function library(string $path = '', string $filter = 'all', string $query = '', int $offset = 0): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->library($path,$filter,$query,$offset);
 }

 #[NoAdminRequired]
 public function status(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->status();
 }

 #[NoAdminRequired]
 public function control(string $action): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->control($action);
 }

 #[NoAdminRequired]
 public function logs(string $date, int $cursor = 0): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->logs($date,$cursor);
 }

 #[NoAdminRequired]
 public function folders(string $path = '/'): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->folders($path);
 }

 #[NoAdminRequired]
 public function result(int $file_id): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->result($file_id);
 }

 #[NoAdminRequired]
 public function maintenance(string $action, string $confirmation): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->maintenance($action,$confirmation);
 }

 #[NoAdminRequired]
 public function test(): DataResponse {
  if($response=$this->unavailable()) return $response;
  return $this->delegate('Admin')->test();
 }
}
