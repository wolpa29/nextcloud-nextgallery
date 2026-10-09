<?php
declare(strict_types=1);
require __DIR__.'/../../lib/AI/Service/ModelConnection.php';

class FixtureConnection extends \OCA\Chronicle\AI\Service\ModelConnection {
    public array $calls = [];
    public function __construct(private array $responses) {}
    protected function fetch(string $url, string $secret): array {
        $this->calls[] = [$url, $secret];
        if (!$this->responses) { throw new \RuntimeException('Unexpected additional request'); }
        return array_shift($this->responses);
    }
}
$checks=0;
function check($ok){global $checks;$checks++;if(!$ok)throw new \Exception('Model connection assertion '.$checks.' failed');}
function response($data,$code=200,$error=null){return ['data'=>$data,'http_status'=>$code,'error'=>$error];}
function config(){return ['vision'=>['url'=>'http://model.test:1234/v1','model'=>'qwen/test','api_key'=>'private-test-key','model_max_pixels'=>4194304],
    'ocr'=>['url'=>'http://model.test:1234/v1','model'=>'qwen/test','api_key'=>'private-test-key','model_max_pixels'=>4194304,'max_batch_images'=>20], 'video'=>['frame_count'=>10]];}
$model=['type'=>'llm','key'=>'qwen/test','display_name'=>'Qwen Test','architecture'=>'qwen35','quantization'=>['name'=>'Q4_K_M'],
    'max_context_length'=>262144,'capabilities'=>['vision'=>true],
    'loaded_instances'=>[['id'=>'qwen/test','config'=>['context_length'=>114810,'private_setting'=>'private-test-key']]]];
$connection=new FixtureConnection([response(['models'=>[$model]])]);$report=$connection->inspect(config());
check(count($connection->calls)===1);check($connection->calls[0][0]==='http://model.test:1234/api/v1/models');
check($report['vision']['reachable']);check($report['vision']['available']);check($report['vision']['loaded']);
check($report['vision']['context_length']===114810);check($report['vision']['max_context_length']===262144);
check($report['vision']['vision']===true);check($report['vision']['quantization']==='Q4_K_M');
check($report['vision']['loaded_models'][0]['context_length']===114810);check($report['ocr']['context_length']===114810);
check(!str_contains(json_encode($report),'private-test-key'));check($report['video_frames']===10);check($report['ocr_batch_images']===20);

$alias=$model;$alias['loaded_instances']=[['id'=>'other','config'=>['context_length'=>4096]],['id'=>'custom-instance','config'=>['context_length'=>32768]]];
$settings=config();$settings['ocr']['model']='custom-instance';
$report=(new FixtureConnection([response(['models'=>[$alias]])]))->inspect($settings);
check($report['ocr']['available']);check($report['ocr']['loaded']);check($report['ocr']['context_length']===32768);
check(count($report['ocr']['loaded_models'])===2);

$unloaded=$model;$unloaded['loaded_instances']=[];
$report=(new FixtureConnection([response(['models'=>[$unloaded]])]))->inspect(config());
check($report['vision']['available']);check($report['vision']['loaded']===false);check($report['vision']['context_length']===null);
check($report['vision']['max_context_length']===262144);
$unknown=$model;$unknown['loaded_instances'][0]['config']['context_length']='114810';
$report=(new FixtureConnection([response(['models'=>[$unknown]])]))->inspect(config());
check($report['vision']['loaded']);check($report['vision']['context_length']===null);
$settings=config();$settings['ocr']['model']='absent';
$report=(new FixtureConnection([response(['models'=>[$model]])]))->inspect($settings);
check(!$report['ocr']['available']);check(!$report['ocr']['loaded']);check($report['ocr']['context_length']===null);

$connection=new FixtureConnection([response(['models'=>[$model]]),response(['models'=>[$model]])]);
$settings=config();$settings['ocr']['api_key']='another-private-key';$connection->inspect($settings);check(count($connection->calls)===2);

$report=(new FixtureConnection([response(['error'=>'private-test-key'],401)]))->inspect(config());
check(!$report['vision']['reachable']);check($report['vision']['error']==='authentication_failed');
check(!str_contains(json_encode($report),'private-test-key'));
foreach([response(null,0,'connection_failed'),response(null,200,'response_too_large'),response(['models'=>'invalid']),response(null,500)] as $failed){
    $report=(new FixtureConnection([$failed]))->inspect(config());check(!$report['vision']['reachable']);check($report['vision']['error']!==null);
}

$connection=new FixtureConnection([response(null,404),response(['data'=>[['type'=>'vlm','id'=>'qwen/test','state'=>'loaded','loaded_context_length'=>8192,'max_context_length'=>262144]]])]);
$report=$connection->inspect(config());check(count($connection->calls)===2);check($report['vision']['source']==='lmstudio_v0');
check($report['vision']['context_length']===8192);check($report['vision']['vision']);
$connection=new FixtureConnection([response(null,404),response(null,404),response(['data'=>[['id'=>'qwen/test']]])]);
$report=$connection->inspect(config());check(count($connection->calls)===3);check($report['vision']['available']);
check($report['vision']['loaded']===null);check($report['vision']['context_length']===null);check($report['vision']['source']==='openai');
check($connection->calls[2][0]==='http://model.test:1234/v1/models');

$rows=\OCA\Chronicle\AI\Service\ModelConnection::nativeModels([null,['type'=>'embedding','key'=>'embedding'],['type'=>'llm','key'=>null],$model]);
check(count($rows)===1);
echo "$checks PHP model connection assertions passed\n";
