<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCA\Nextgallery\Service\SearchIndexChanges;
/** User filesystem and share changes invalidate catalogs, including shared paths. */
class SearchIndexChanged implements IEventListener {
 public function __construct(private SearchIndexChanges $changes) {}
 public function handle(Event $event):void {
  try{
   $nodes=[];
   if(method_exists($event,'getNode'))$nodes[]=$event->getNode();
   if(method_exists($event,'getTarget'))$nodes[]=$event->getTarget();
   if(method_exists($event,'getShare'))$nodes[]=$event->getShare()->getNode();
   // Appdata previews and internal caches must not invalidate the index on every view.
   if($nodes&&!array_filter($nodes,fn($node)=>is_object($node)&&!preg_match('~^/(?:appdata_[^/]+|\.nextgallery_[^/]*)(?:/|$)~',$node->getPath())))return;
  }catch(\Throwable){} // Deleted nodes can no longer expose a path; invalidate conservatively.
  $this->changes->catalog();
 }
}
