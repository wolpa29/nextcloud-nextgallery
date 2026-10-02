<?php
declare(strict_types=1);
namespace OCA\MediaAI\Controller;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCA\MediaAI\Service\{Settings,State};
class AdminController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private State $state) { parent::__construct('media_ai',$request); }
    public function config(): DataResponse { return new DataResponse($this->settings->publicConfig()); }
    public function save(array $config): DataResponse { return new DataResponse($this->settings->save($config)); }
    public function status(): DataResponse { return new DataResponse($this->state->status()); }
    public function control(string $action): DataResponse { return new DataResponse($this->state->setControl($action)); }
    public function logs(string $date, int $cursor = 0): DataResponse { return new DataResponse($this->state->logs($date,$cursor)); }
    public function test(): DataResponse {
        $result = [];
        foreach (['vision','ocr'] as $stage) {
            $config=$this->settings->workerConfig()[$stage];
            $handle=curl_init($config['url'].'/models');
            $body=''; $tooLarge=false;
            curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['api_key']],
                CURLOPT_WRITEFUNCTION=>function($curl,$chunk) use (&$body,&$tooLarge) { if(strlen($body)+strlen($chunk)>1048576) {$tooLarge=true; return 0;} $body.=$chunk; return strlen($chunk); }]);
            curl_exec($handle); $code=curl_getinfo($handle,CURLINFO_HTTP_CODE); curl_close($handle);
            $models=json_decode($body,true)['data']??[]; $ids=array_values(array_filter(array_column($models,'id'),'is_string'));
            $result[$stage]=['reachable'=>$code===200 && !$tooLarge,'available'=>in_array($config['model'],$ids,true),'models'=>$ids,'http_status'=>$code];
        }
        return new DataResponse($result);
    }
}
