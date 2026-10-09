<?php
declare(strict_types=1);
namespace OCA\Chronicle\Controller;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCA\Chronicle\AI\Service\Preferences;
class AiPreferencesController extends Controller {
    public function __construct(IRequest $request,private Preferences $preferences){parent::__construct('chronicle',$request);}
    #[NoAdminRequired] #[NoCSRFRequired]
    public function get(): DataResponse {return new DataResponse(['language'=>$this->preferences->language()]);}
    #[NoAdminRequired]
    public function save(string $language): DataResponse {
        if(!in_array($language,['de','en'],true))return new DataResponse(['error'=>'Invalid language'],400);
        $this->preferences->saveLanguage($language);return new DataResponse(['language'=>$language]);
    }
}
