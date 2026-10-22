<?php
/** Fault-oriented lock lifecycle checks, entirely synthetic/in-memory. */
declare(strict_types=1);
require __DIR__.'/php_faces.php';

class ArtifactProbeLocks implements \OCP\Lock\ILockingProvider {
    public array $held=[];
    public function acquireLock(string $key,int $type):bool {
        if(isset($this->held[$key]))throw new RuntimeException('Synthetic lock busy');
        $this->held[$key]=true;return true;
    }
    public function releaseLock(string $key,int $type):bool {
        if(!isset($this->held[$key]))throw new RuntimeException('Release without acquire');
        unset($this->held[$key]);return true;
    }
    public function globalHeld():bool {return isset($this->held[hash('sha256','nextgallery:faces:artifacts')]);}
}
class ArtifactProbeDB extends TestDB {
    public bool $fail=false;
    public int $checkedCommits=0;
    public int $checkedRollbacks=0;
    public function __construct(public ArtifactProbeLocks $probe) {parent::__construct();}
    public function executeStatement(string $query,array $params=[]):int {
        if($this->fail&&str_contains($query,'DELETE FROM *PREFIX*nextgallery_face_rules'))throw new RuntimeException('Synthetic mutation failure');
        return parent::executeStatement($query,$params);
    }
    public function commit():void {
        if(!$this->probe->globalHeld())throw new RuntimeException('Artifact lock released before commit');
        $this->checkedCommits++;parent::commit();
    }
    public function rollBack():void {
        if(!$this->probe->globalHeld())throw new RuntimeException('Artifact lock released before rollback');
        $this->checkedRollbacks++;parent::rollBack();
    }
}
$probe=new ArtifactProbeLocks();$probeDb=new ArtifactProbeDB($probe);
$probeFaces=new \OCA\Nextgallery\AI\Service\Faces($probeDb,$settings,$inventory,$library,$probe);
$probeFaces->reset('lock-alice');
if($probeDb->checkedCommits!==1||$probe->held)throw new RuntimeException('Successful reset did not hold/release mutation lock');
$before=$probeFaces->accountState('lock-alice');$probeDb->fail=true;
try{$probeFaces->reset('lock-alice');throw new LogicException('Expected failure');}catch(RuntimeException $e){if($e->getMessage()!=='Synthetic mutation failure')throw $e;}
if($probeDb->checkedRollbacks!==1||$probe->held||$probeFaces->accountState('lock-alice')!==$before)throw new RuntimeException('Rollback failed to preserve state/release locks');
$probeDb->fail=false;
$lock=new ReflectionMethod($probeFaces,'lock');$unlock=new ReflectionMethod($probeFaces,'unlock');
$lock->invoke($probeFaces,'lock-alice');
try{$probeFaces->reset('lock-bob');throw new LogicException('Expected contention');}catch(RuntimeException $e){if($e->getMessage()!=='Synthetic lock busy')throw $e;}
if(isset($probe->held[hash('sha256','nextgallery:faces:lock-bob')]))throw new RuntimeException('Global contention leaked account lock');
if(!$probe->globalHeld())throw new RuntimeException('Contending account released original global lock');
$unlock->invoke($probeFaces,'lock-alice');
if($probe->held)throw new RuntimeException('Original account failed to release locks');
echo "5 artifact transaction/failure/contention assertions passed\n";
