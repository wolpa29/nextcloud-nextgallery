<?php
/** Real DB re-sorting of one person: clear matches move, references and unclear faces stay. Synthetic images only. */
declare(strict_types=1);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Nextgallery\AI\Service\{Settings,Faces,FaceArtifacts};
use OCA\Nextgallery\Exception\FaceConflictException;
use OCA\Nextgallery\Service\Library;
if(!is_file('/test-output/ISOLATED_SYNTHETIC_TEST'))throw new RuntimeException('Synthetic guard missing');
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().' '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n");exit(1);});
$checks=0;function resortCheck(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$root=Server::get(\OCP\Files\IRootFolder::class);$settings=Server::get(Settings::class);$faces=Server::get(Faces::class);$art=Server::get(FaceArtifacts::class);$library=Server::get(Library::class);$db=Server::get(\OCP\IDBConnection::class);
$old=$settings->get();$uid='resort-owner';$users->get($uid)?->delete();
// Unit vector along the given axes, with a small offset on a private axis so faces are not identical.
function face(array $axes,int $noise):array{$v=array_fill(0,128,0.0);foreach($axes as $a)$v[$a]=1.0;$v[$noise]=0.2;$n=sqrt(array_sum(array_map(fn($x)=>$x*$x,$v)));return array_map(fn($x)=>$x/$n,$v);}
try {
 $c=$old;$c['faces']['enabled']=true;$c['faces']['cosine_threshold']=0.55;$settings->save($c);
 $session->setUser($users->createUser($uid,'SyntheticResortOnly2026!'));$folder=$root->getUserFolder($uid)->newFolder('ResortQa');
 $settings->saveAccount($uid,['enabled'=>false,'folders'=>['/ResortQa'],'folder_stages'=>['/ResortQa'=>['faces']],'faces_filters'=>['min_face_area'=>0.01,'min_sharpness'=>0.0,'min_group_photos'=>2]]);
 $lena=str_repeat('a',32);$max=str_repeat('b',32);$rare=str_repeat('c',32);$vale=str_repeat('f',32);
 // name => [group, vector, small face]
 $faceSpec=[
  'lena-1'=>[$lena,face([0],20),false],'lena-2'=>[$lena,face([0],21),false],'lena-3'=>[$lena,face([0],22),false],
  'max-1'=>[$max,face([1],23),false],'max-2'=>[$max,face([1],24),false],'max-3'=>[$max,face([1],25),false],
  'rare-1'=>[$rare,face([4],26),false],
  'vale-ref'=>[$vale,face([2],27),false],
  'v-lena-a'=>[$vale,face([0],30),false],'v-lena-b'=>[$vale,face([0],31),false],'v-lena-small'=>[$vale,face([0],32),true],
  'v-max-a'=>[$vale,face([1],33),false],'v-max-b'=>[$vale,face([1],34),false],
  'v-own'=>[$vale,face([2],35),false],
  'v-unknown-a'=>[$vale,face([3],36),false],'v-unknown-b'=>[$vale,face([3],37),false],
  'v-between'=>[$vale,face([0,1,7],38),false],
  'v-forbidden'=>[$vale,face([0],39),false],
  'v-rare'=>[$vale,face([4],40),false],
 ];
 $files=[];foreach($faceSpec as $name=>$_)$files[$name]=$folder->newFile("$name.png",file_get_contents('/samples/dog.png'));
 $library->save($uid,['roots'=>['/ResortQa']]);do{$step=$library->step($uid,false,100,10);}while($step['running']);
 $account=$faces->accountState($uid);$rev=$art->currentRevision();$ids=[];
 foreach($faceSpec as $name=>[$gid,$vec,$small]){
  $out=$faces->publish($uid,$files[$name]->getId(),$files[$name]->getEtag(),$account['epoch'],$account['generation'],$account['rules_revision'],Faces::MODEL_SPACE,[['bbox'=>$small?[.1,.1,.05,.05]:[.1,.1,.2,.2],'vector'=>$vec,'score'=>.95,'sharpness'=>150.0,'group_id'=>$gid]],$rev);
  $ids[$name]=$out[0]['id'];
 }
 $rr=fn()=>$faces->accountState($uid)['rules_revision'];
 $faces->action($uid,$lena,'rename',null,null,$rr(),'Lena');$faces->action($uid,$max,'rename',null,null,$rr(),'Max');$faces->action($uid,$vale,'rename',null,null,$rr(),'Valentina');
 // The user confirmed one face of Valentina; it is her reference. One face was already split off Lena once.
 $faces->action($uid,$vale,'resolve',null,[$ids['vale-ref']],$rr());
 $db->executeStatement('INSERT INTO *PREFIX*nextgallery_face_rules (user_id, kind, detection_id, group_id, created_at) VALUES (?, ?, ?, ?, ?)',[$uid,'cannot_link',$ids['v-forbidden'],$lena,time()]);
 $group=function(string $name)use($db,$uid,$ids):string{$r=$db->executeQuery('SELECT group_id FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND id = ?',[$uid,$ids[$name]]);$g=(string)$r->fetchOne();$r->closeCursor();return $g;};

 $before=$rr();
 $plan=$faces->resort($uid,$vale,'',[],'stay',true,$before);
 $s=$plan['summary'];$targets=array_column($s['targets'],'photo_count','id');
 resortCheck($plan['dry_run']===true&&$rr()===$before,'a preview changes nothing');
 resortCheck($group('v-lena-a')===$vale,'a preview moves no photo');
 resortCheck($s['checked']===11,'only automatic faces are compared, the confirmed one is the reference');
 resortCheck($s['kept']===1,'the confirmed face is kept');
 resortCheck(($targets[$lena]??0)===3&&($targets[$max]??0)===2,'clear matches go to Lena and Max, including a small face');
 resortCheck(!isset($targets[$rare]),'a person below the overview minimum is no target');
 resortCheck($s['stay']===1,'a face matching the reference stays');
 resortCheck($s['rest']===5,'unclear, forbidden, unknown and rare-only faces have no clear match');
 resortCheck($s['new_groups']===4&&$s['new_people']===1,'the two unknown faces form one new person');
 $labels=array_column($s['targets'],'label','id');resortCheck($labels[$lena]==='Lena','targets carry their names');
 resortCheck(!str_contains(json_encode($plan),'vector'),'the plan never exposes vectors');

 $kept=$faces->resort($uid,$vale,'',[$ids['v-lena-a']],'stay',true,$before)['summary'];
 resortCheck((array_column($kept['targets'],'photo_count','id')[$lena]??0)===2&&$kept['kept']===2,'selected photos stay with the person');

 $stale=false;try{$faces->resort($uid,$vale,'',[],'new',false,$before-1);}catch(FaceConflictException){$stale=true;}
 resortCheck($stale,'a stale rules revision is rejected');
 $foreign=false;try{$faces->resort($uid,$vale,'',[$ids['lena-1']],'stay',true,$before);}catch(\OCP\AppFramework\OCS\OCSBadRequestException){$foreign=true;}
 resortCheck($foreign,'only faces of this person can be kept');

 $applied=$faces->resort($uid,$vale,'',[],'new',false,$before);
 resortCheck($applied['rules_revision']===$before+1&&$rr()===$before+1,'applying is one correction with one revision');
 foreach(['v-lena-a','v-lena-b','v-lena-small'] as $n)resortCheck($group($n)===$lena,"$n moved to Lena");
 foreach(['v-max-a','v-max-b'] as $n)resortCheck($group($n)===$max,"$n moved to Max");
 foreach(['vale-ref','v-own'] as $n)resortCheck($group($n)===$vale,"$n stays with Valentina");
 resortCheck($group('v-unknown-a')===$group('v-unknown-b')&&!in_array($group('v-unknown-a'),[$vale,$lena,$max,$rare],true),'unknown faces share a new person');
 resortCheck($group('v-forbidden')!==$lena&&$group('v-forbidden')!==$vale,'a face split off Lena never returns there');
 resortCheck(count($applied['created_ids'])===4&&count($applied['moved'])===10,'new people and every moved face are reported for undo');
 $r=$db->executeQuery('SELECT manual FROM *PREFIX*nextgallery_face_detections WHERE user_id = ? AND id = ?',[$uid,$ids['v-lena-small']]);
 resortCheck((int)$r->fetchOne()===0,'a moved face stays automatic and keeps its quality filter');$r->closeCursor();
 $people=array_column($faces->subjects($uid)['items'],'photo_count','label');
 resortCheck(($people['Lena']??0)===5&&($people['Max']??0)===5&&($people['Valentina']??0)===2,'the overview shows the new counts');

 $faces->resortUndo($uid,$vale,array_column($applied['moved'],'detection_id'),$rr(),'Valentina',null);
 foreach(['v-lena-a','v-lena-small','v-max-a','v-unknown-a','v-forbidden'] as $n)resortCheck($group($n)===$vale,"undo returns $n");
 resortCheck($group('lena-1')===$lena,'undo leaves the people\'s own photos alone');
 $q=$db->getQueryBuilder();$q->select('id')->from('nextgallery_faces_groups')->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('id',$q->createNamedParameter($applied['created_ids'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
 resortCheck($q->executeQuery()->fetchAll()===[],'undo removes the people it emptied');

 // Valentina does not appear in this gallery: even her confirmed face here is distributed.
 // One face has no current comparable version (its stored version differs), but is still shown here.
 $db->executeStatement('UPDATE *PREFIX*nextgallery_face_detections SET etag = ? WHERE user_id = ? AND id = ?',['stale-version',$uid,$ids['v-rare']]);
 $absent=$faces->resort($uid,$vale,'',[$ids['v-own']],'stay',true,$rr(),true)['summary'];
 resortCheck($absent['checked']===11&&$absent['stay']===0&&$absent['kept']===0,'an absent person keeps nothing, not even confirmed or selected faces');
 resortCheck($absent['rest']===7,'faces like her own have no clear target and become new people');
 $gone=$faces->resort($uid,$vale,'',[],'stay',false,$rr(),true);
 resortCheck($faces->media($uid,$vale)['items']===[],'no photo of an absent person remains in the gallery');
 resortCheck($group('vale-ref')!==$vale&&$group('vale-ref')===$group('v-own'),'her two faces form one new person');
 resortCheck($group('v-rare')!==$vale,'a face that cannot be compared leaves the absent person too');
 $r=$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_face_rules WHERE user_id = ? AND kind = ? AND group_id = ?',[$uid,'cannot_link',$vale]);
 resortCheck((int)$r->fetchOne()===count($gone['moved']),'moved faces may not join the absent person again');$r->closeCursor();
 $faces->resortUndo($uid,$vale,array_column($gone['moved'],'detection_id'),$rr(),'Valentina',null);
 resortCheck($group('vale-ref')===$vale&&$group('v-lena-a')===$vale,'undo returns every face of the absent person');
 $r=$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*nextgallery_face_rules WHERE user_id = ? AND kind = ? AND group_id = ?',[$uid,'cannot_link',$vale]);
 resortCheck((int)$r->fetchOne()===0,'undo lifts the rules');$r->closeCursor();

 $faces->resort($uid,$vale,'',[],'stay',false,$rr());
 resortCheck($group('v-unknown-a')===$vale&&$group('v-between')===$vale&&$group('v-lena-a')===$lena,'without grouping, unclear faces stay');
 echo "$checks real re-sort assertions passed\n";
}finally{$users->get($uid)?->delete();$settings->save($old);}
