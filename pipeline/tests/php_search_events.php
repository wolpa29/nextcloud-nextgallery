<?php
declare(strict_types=1);
namespace OCP\EventDispatcher {class Event {} interface IEventListener {public function handle(Event $event):void;}}
namespace OCP {interface IConfig {}}
namespace {
require __DIR__.'/../../lib/Service/SearchIndexChanges.php';require __DIR__.'/../../lib/Listener/SearchIndexChanged.php';
class EventChanges extends \OCA\Nextgallery\Service\SearchIndexChanges {public int $calls=0;public function __construct(){} public function catalog():void{$this->calls++;}}
class SearchNode {public function __construct(private string $path){} public function getPath(){return $this->path;}}
class SearchNodeEvent extends \OCP\EventDispatcher\Event {public function __construct(private $node){}public function getNode(){return $this->node;}}
class SearchRenameEvent extends SearchNodeEvent {public function __construct($node,private $target){parent::__construct($node);}public function getTarget(){return $this->target;}}
class SearchShareEvent extends \OCP\EventDispatcher\Event {public function getShare(){return new class{public function getNode(){return new SearchNode('/synthetic/files/Shared');}};}}
$c=new EventChanges();$l=new \OCA\Nextgallery\Listener\SearchIndexChanged($c);$n=0;
foreach(['/appdata_synthetic/preview/123/32.jpg','/appdata_synthetic/nextgallery/state.json','/.nextgallery_search/synthetic/index.sqlite3'] as $path){$before=$c->calls;$l->handle(new SearchNodeEvent(new SearchNode($path)));if($c->calls!==$before)throw new \RuntimeException('Internal cache invalidated user search');$n++;}
foreach(['/synthetic/files/Photos/a.png','/synthetic/files','/synthetic/files/Folder','/mounted-shared/synthetic/photo.png'] as $path){$before=$c->calls;$l->handle(new SearchNodeEvent(new SearchNode($path)));if($c->calls!==$before+1)throw new \RuntimeException('User filesystem change was ignored');$n++;}
$before=$c->calls;$l->handle(new SearchRenameEvent(new SearchNode('/appdata_synthetic/tmp'),new SearchNode('/synthetic/files/moved.png')));if($c->calls!==$before+1)throw new \RuntimeException('Rename target not tracked');$n++;
$before=$c->calls;$l->handle(new SearchShareEvent());if($c->calls!==$before+1)throw new \RuntimeException('Share not tracked');$n++;
$l->handle(new SearchNodeEvent(new class{public function getPath(){throw new \RuntimeException('Synthetic deleted node');}}));if($c->calls!==$before+2)throw new \RuntimeException('Deleted node skipped');$n++;
echo "$n user-files/share/deleted-node/internal-preview invalidation assertions passed\n";
}
