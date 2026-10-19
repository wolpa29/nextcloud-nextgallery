<?php
declare(strict_types=1);
namespace OCA\Nextgallery\AI\Service;
use OCP\ICacheFactory;
use OCP\AppFramework\OCS\OCSBadRequestException;

/** Read-only presentation of the selected inventory, published results and worker queue. */
class Library {
    public function __construct(private Inventory $inventory, private Settings $settings, private State $state, private ?ICacheFactory $caches = null, private ?Embeddings $embeddings = null) {}
    public function forUser(string $uid):self {$copy=clone $this;$copy->inventory=$this->inventory->forUser($uid);$copy->settings=$this->settings->forUser($uid);$copy->state=$this->state->forUser($uid);return $copy;}
    private function queue(string $kind='descriptions'): array {
        $path=$this->state->directory().'/native-'.$kind.'.sqlite3';
        if(!is_file($path))$path=$this->state->directory().'/native.sqlite3';
        if (!is_file($path) || is_link($path)) { return []; }
        $bootstrap=$this->state->rootDirectory().'/bootstrap.json';
        if(!is_file($bootstrap) || is_link($bootstrap) || filesize($bootstrap)>1048576) { return []; }
        $identity=json_decode((string)file_get_contents($bootstrap),true);
        $url=$identity['url']??null;
        if(!is_string($url)) { return []; }
        $config=$this->settings->get();
        $scope=hash('sha256',json_encode(['native',$identity['scope_identity']??rtrim($url,'/').'/index.php/apps/nextgallery/ai/worker/',$config['user_id'],$config['folders'],$config['exclude_folders']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS));
        $db=new \PDO('sqlite:file:'.$path.'?mode=ro', null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON'); $db->exec('PRAGMA busy_timeout=250');
        $query=$db->prepare('SELECT locator,etag,status,error,retry_at,analyzed_at,publish_error FROM files WHERE active=1 ORDER BY CASE WHEN scope=? THEN 1 ELSE 0 END ASC, analyzed_at ASC');$query->execute([$scope]);$rows=$query->fetchAll(\PDO::FETCH_ASSOC);
        $result=[];
        foreach ($rows as $row) { $result[(int)$row['locator']]=$row; }
        return $result;
    }
    private function embeddingQueue(?string $space):array {
        $path=$this->state->directory().'/embeddings.sqlite3';
        if(!$space||!is_file($path)||is_link($path))return [];
        $db=new \PDO('sqlite:file:'.$path.'?mode=ro',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA query_only=ON');$db->exec('PRAGMA busy_timeout=250');
        $query=$db->prepare('SELECT path,etag,status,error FROM embeddings WHERE active=1 AND space=?');$query->execute([$space]);$result=[];foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row)$result[$row['path']]=$row;return $result;
    }
    public static function classify(array $item, ?array $queued): string {
        // Published metadata is authoritative, including after queue/app/model changes.
        if ($item['analyzed_etag']) { return $item['analyzed_etag']===$item['etag'] ? 'done' : 'changed'; }
        if ($queued && $queued['etag']===$item['etag']) {
            if ($queued['status']==='error') { return 'error'; }
            if ($queued['status']==='pending') { return $item['analyzed_etag'] ? 'changed' : 'pending'; }
            if (!empty($queued['publish_error'])) { return 'publishing'; }
        }
        if ($item['analyzed_etag'] && $item['analyzed_etag']===$item['etag']) { return 'done'; }
        return $item['analyzed_etag'] ? 'changed' : 'pending';
    }
    public static function totals(): array { return ['total'=>0,'done'=>0,'pending'=>0,'error'=>0,'changed'=>0,'publishing'=>0,'images'=>0,'videos'=>0,'embedded'=>0,'embedding_pending'=>0,'embedding_error'=>0,'ocr_done'=>0,'ocr_pending'=>0,'ocr_error'=>0,'ocr_total'=>0,'description_total'=>0,'embedding_total'=>0]; }
    private function snapshot(): array {
        $config=$this->settings->get();$current=$this->state->status();$current=$current['pipelines']['embeddings']??$current;$rebuild=$current['embedding_rebuild']??[];
        $space=$rebuild['target_space']??$rebuild['approved_space']??$current['embedding_space']??$current['last_run']['embedding']['space']??null;
        // A changed model must never present old vectors as completed for the new model.
        if(!empty($rebuild['required'])&&!isset($rebuild['target_space']))$space='';
        $cache=$this->caches?->createLocal('nextgallery_ai_library');
        $key=hash('sha256',json_encode([4,$config['user_id'],$config['folders'],$config['exclude_folders'],$config['folder_stages'],Settings::embeddingSignature($config),$space,$rebuild['required']??false],JSON_THROW_ON_ERROR));
        $stored=$cache?->get($key); if (is_array($stored)) { return $stored; }
        $queue=$this->queue();$ocrQueue=$this->queue('ocr'); $rows=$this->inventory->snapshot();
        $stored=$this->embeddings?->processingStatuses($config['user_id'],array_column($rows,'file_id'),$space)??[];$embeddingQueue=$this->embeddingQueue($space);
        $folders=[''=>['path'=>'','name'=>'Alle ausgewählten Dateien','counts'=>self::totals()]]; $recent=[];
        foreach ($rows as &$row) {
            $queued=$queue[$row['file_id']]??null;$stages=$row['stages']??Settings::STAGES;unset($row['stages']);
            // A pipeline deselected for this folder neither counts as pending nor as done.
            $row['status']=in_array('descriptions',$stages,true)?self::classify($row,$queued):'not_applicable';
            $row['has_description']=!empty($row['analyzed_etag']);$row['has_ocr']=!empty($row['ocr_etag']);$row['has_metadata']=$row['has_description']||$row['has_ocr'];
            $ocrItem=$row;$ocrItem['analyzed_etag']=$row['ocr_etag']??null;$oq=$ocrQueue[$row['file_id']]??null;
            $row['ocr_status']=str_starts_with($row['mime'],'video/')||!in_array('ocr',$stages,true)?'not_applicable':self::classify($ocrItem,$oq);
            $row['ocr_error']=$row['ocr_status']==='error'?($oq['error']??''):($row['ocr_status']==='publishing'?($oq['publish_error']??''):'');
            $row['next_pending']=!$row['has_description'] && !in_array($row['status'],['done','not_applicable'],true);
            $row['error']=$row['status']==='error' ? ($queued['error']??'') : ($row['status']==='publishing' ? ($queued['publish_error']??'') : '');
            $row['retry_at']=$row['status']==='error' ? (float)($queued['retry_at']??0) : 0;
            $embedding=$embeddingQueue[$row['path']]??null;$row['has_embedding']=isset($stored[$row['file_id']]);
            $row['embedding_status']=!in_array('embeddings',$stages,true)?'not_applicable':($row['has_embedding']?'done':($embedding&&$embedding['etag']===$row['etag']&&$embedding['status']==='error'?'error':'pending'));
            $row['embedding_error']=$row['embedding_status']==='error'?($embedding['error']??''):'';
            $row['name']=basename($row['path']); $row['parent']=dirname($row['path']);
            if ($row['parent']==='.') { $row['parent']=''; }
            $parent=$row['parent']; $paths=[''];
            while ($parent!=='') {
                $paths[]=$parent;
                if (!isset($folders[$parent])) { $folders[$parent]=['path'=>$parent,'name'=>basename($parent),'counts'=>self::totals()]; }
                $parent=dirname($parent); if ($parent==='.') { $parent=''; }
            }
            foreach ($paths as $path) {
                $folders[$path]['counts']['total']++;if($row['ocr_status']!=='not_applicable'){$folders[$path]['counts']['ocr_total']++;$folders[$path]['counts'][match($row['ocr_status']){'done','changed'=>'ocr_done','error'=>'ocr_error',default=>'ocr_pending'}]++;}if($row['embedding_status']!=='not_applicable'){$folders[$path]['counts']['embedding_total']++;$folders[$path]['counts'][match($row['embedding_status']){'done'=>'embedded','error'=>'embedding_error',default=>'embedding_pending'}]++;}if($row['status']!=='not_applicable'){$folders[$path]['counts']['description_total']++;$folders[$path]['counts'][$row['status']]++;}
                $folders[$path]['counts'][str_starts_with($row['mime'],'video/')?'videos':'images']++;
            }
            if ($row['status']==='done') { $recent[]=['file_id'=>$row['file_id'],'path'=>$row['path'],'name'=>$row['name'],'updated_at'=>$row['updated_at']]; }
            unset($row['etag'],$row['analyzed_etag'],$row['ocr_etag']);
        }
        unset($row); usort($recent,fn($a,$b)=>$b['updated_at']<=>$a['updated_at']);
        foreach ($folders as &$folder) { $counts=$folder['counts']; $folder['status']=$counts['total']>0 && $counts['description_total']===0 ? 'not_applicable' : ($counts['description_total']>0 && ($counts['done']+$counts['changed'])===$counts['description_total'] ? ($counts['changed']?'changed':'done') : ($counts['error'] ? 'error' : 'pending')); }
        unset($folder);
        $snapshot=['rows'=>$rows,'folders'=>$folders,'recent'=>array_slice($recent,0,5),'generated_at'=>time()];
        $cache?->set($key,$snapshot,10); return $snapshot;
    }
    public function nodeStatuses(array $nodes): array {
        $snapshot=$this->snapshot(); $ids=array_fill_keys(array_column($nodes,'file_id'),true); $selected=[];
        foreach ($snapshot['rows'] as $row) { if(isset($ids[$row['file_id']])) { $selected[$row['file_id']]=['status'=>$row['status'],'updated_at'=>$row['updated_at']]; } }
        foreach ($nodes as $node) {
            if ($node['folder']) {
                $folder=$snapshot['folders'][trim($node['path'],'/')]??null;
                $selected[$node['file_id']]=$folder ? ['status'=>$folder['status'],'counts'=>$folder['counts'],'selected_only'=>true] : ['status'=>'unselected'];
            }
        }
        return $selected;
    }
    public function browse(string $path='', string $filter='all', string $query='', int $offset=0,string $embeddingFilter='all',string $ocrFilter='all'): array {
        if (strlen($path)>2000 || str_contains($path,"\0") || str_contains($path,'\\') || preg_match('~(^|/)(\.|\.\.)(/|$)~',$path) || $offset<0 || $offset>10000000 || strlen($query)>200 || !in_array($ocrFilter,['all','done','pending','error'],true) || !in_array($embeddingFilter,['all','done','pending','error'],true) || !in_array($filter,['all','analyzed','done','pending','unprocessed','error','changed'],true)) { throw new OCSBadRequestException('Ungültige Dateiauswahl'); }
        $path=trim($path,'/'); $snapshot=$this->snapshot(); $config=$this->settings->get();
        if (!isset($snapshot['folders'][$path])) { throw new OCSBadRequestException('Ordner außerhalb der Auswahl oder ohne Medien'); }
        $folder=$snapshot['folders'][$path]; $children=[]; $files=[];
        foreach ($snapshot['folders'] as $childPath=>$child) {
            if ($childPath!=='' && (dirname($childPath)==='.'?'':dirname($childPath))===$path) { $children[]=$child; }
        }
        usort($children,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        // Search/filter recurse within the current folder. Unfiltered browsing is one level.
        foreach ($snapshot['rows'] as $row) {
            $inside=$path==='' || str_starts_with($row['path'],$path.'/');
            if (!$inside || ($filter==='all' && $embeddingFilter==='all' && $ocrFilter==='all' && $query==='' && $row['parent']!==$path)) { continue; }
            if ($filter==='analyzed' ? !$row['has_description'] : ($filter==='pending' ? !$row['next_pending'] : ($filter==='unprocessed' ? !in_array($row['status'],['pending','publishing'],true) : ($filter!=='all' && $row['status']!==$filter)))) { continue; }
            if($ocrFilter!=='all'&&($ocrFilter==='done'?!in_array($row['ocr_status'],['done','changed'],true):($ocrFilter==='pending'?!in_array($row['ocr_status'],['pending','publishing'],true):$row['ocr_status']!==$ocrFilter)))continue;
            if($embeddingFilter!=='all'&&$row['embedding_status']!==$embeddingFilter)continue;
            if ($query!=='' && mb_stripos($row['path'],$query)===false) { continue; }
            $files[]=$row;
        }
        usort($files,fn($a,$b)=>strnatcasecmp($a['path'],$b['path']));
        $now=new \DateTimeImmutable('now',new \DateTimeZone($config['timezone']));
        [$hours,$minutes]=array_map('intval',explode(':',$config['schedule_time']));
        $next=$now->setTime($hours,$minutes); if ($next<=$now) { $next=$next->modify('+1 day'); }
        $eligible=0;
        foreach ($snapshot['rows'] as $row) { if ($row['next_pending'] && ($row['status']!=='error' || $row['retry_at']<=($config['enabled']?$next->getTimestamp():time()))) { $eligible++; } }
        $roots=[];foreach($config['folders'] as $selectedPath){$selectedPath=trim($selectedPath,'/');if(isset($snapshot['folders'][$selectedPath]))$roots[]=$snapshot['folders'][$selectedPath];}
        $current=$this->state->status();$current=$current['pipelines']['descriptions']??$current;
        return ['counts'=>$snapshot['folders']['']['counts'],'folder'=>$folder,'folders'=>$children,'files'=>array_slice($files,$offset,100),'matched'=>count($files),'offset'=>$offset,'next_offset'=>$offset+100<count($files)?$offset+100:null,'recent'=>$snapshot['recent'],'generated_at'=>$snapshot['generated_at'],'current_file_id'=>$current['state']==='running'?($current['current']['file_id']??null):null,'schedule'=>['enabled'=>$config['enabled'],'next_at'=>$config['enabled']?$next->getTimestamp():null,'timezone'=>$config['timezone'],'eligible'=>$eligible,'basis'=>'current_inventory'],'roots'=>$roots,'selection'=>['folders'=>$config['folders'],'excluded'=>$config['exclude_folders'],'stages'=>$config['folder_stages']]];
    }
}
