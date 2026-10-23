<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Controller;
use OCP\{IRequest,IUserSession};
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired,NoCSRFRequired};
use OCP\AppFramework\OCS\{OCSBadRequestException,OCSForbiddenException};
use OCA\Nextgallery\Service\NativeSearch;
class NativeSearchController extends OCSController {
 public function __construct(IRequest $request,private IUserSession $session,private NativeSearch $searchService){parent::__construct('nextgallery',$request);}
 #[NoAdminRequired] #[NoCSRFRequired]
 public function search(string $query='',array $roots=[],string $kind='',int $offset=0,int $limit=60,string $phase='hybrid',string $period='',string $mode='folder',string $timezone='UTC',string $search_token='',string $subject_id='',string $source=''):DataResponse {
  if($this->request->getHeader('OCS-APIRequest')!=='true')throw new OCSForbiddenException('OCS API header required');
  $user=$this->session->getUser();if(!$user)throw new OCSForbiddenException('Authentication required');
  try{return new DataResponse($this->searchService->search($user->getUID(),$query,$roots,$kind,$offset,$limit,$phase,$period,$mode,$timezone,$search_token,false,$subject_id,$source),200,['Cache-Control'=>'private, no-store']);}
  catch(\InvalidArgumentException $e){throw new OCSBadRequestException($e->getMessage());}
 }
}
