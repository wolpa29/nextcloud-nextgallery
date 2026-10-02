<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
class PageController extends Controller {
    public function __construct(IRequest $request) { parent::__construct('media_ai',$request); }
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse { return new TemplateResponse('media_ai','index'); }
}
