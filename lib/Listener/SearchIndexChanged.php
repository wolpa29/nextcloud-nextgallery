<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCP\Share\IManager as IShareManager;
use OCA\Nextgallery\Service\SearchIndexChanges;

/** User filesystem and share changes invalidate catalogs, with scoped owner/share targeting. */
class SearchIndexChanged implements IEventListener {
 public function __construct(private SearchIndexChanges $changes, private ?IShareManager $shareManager = null) {}

 public function handle(Event $event):void {
  try {
   $nodes=[];
   if(method_exists($event,'getNode'))$nodes[]=$event->getNode();
   if(method_exists($event,'getTarget'))$nodes[]=$event->getTarget();
   if(method_exists($event,'getShare'))$nodes[]=$event->getShare()->getNode();

   // Appdata previews and internal caches must not invalidate the index on every view.
   if($nodes&&!array_filter($nodes,fn($node)=>is_object($node)&&!preg_match('~^/(?:appdata_[^/]+|\.nextgallery_[^/]*)(?:/|$)~',$node->getPath())))return;

   $isStructural = method_exists($event,'getShare');
   $affectedUsers=[];
   $fileIds=[];

   foreach($nodes as $node){
    if(!is_object($node))continue;
    if($node instanceof \OCP\Files\Folder||(method_exists($node,'getType')&&$node->getType()===\OCP\Files\FileInfo::TYPE_FOLDER)){
     $isStructural=true;
    }
    if(method_exists($node,'getId')){
     $id=(int)$node->getId();
     if($id>0)$fileIds[]=$id;
    }

    if(method_exists($node,'getOwner')){
     $owner=$node->getOwner();
     if(is_object($owner)&&method_exists($owner,'getUID')&&is_string($owner->getUID())&&$owner->getUID()!==''){
      $affectedUsers[$owner->getUID()]=true;
     }
    }

    if($this->shareManager!==null&&$node instanceof \OCP\Files\Node){
     try {
      $access=$this->shareManager->getAccessList($node);
      if(!empty($access['public'])||!empty($access['remote'])){
       // Public links and federated shares cannot be scoped to local accounts; safely fallback to catalog.
       $this->changes->catalog();
       return;
      }
      foreach($access['users']??[] as $sharedUser){
       if(is_string($sharedUser)&&$sharedUser!==''){
        $affectedUsers[$sharedUser]=true;
       }
      }
     } catch (\Throwable) {
      // If access list resolution fails on a node, fall back safely.
      $this->changes->catalog();
      return;
     }
    }
   }

   // Resolve share owner and recipient for share events
   if(method_exists($event,'getShare')){
    $isStructural=true;
    $share=$event->getShare();
    if(is_object($share)){
     if(method_exists($share,'getShareOwner')&&is_string($share->getShareOwner())&&$share->getShareOwner()!==''){
      $affectedUsers[$share->getShareOwner()]=true;
     }
     $shareType=method_exists($share,'getShareType')?(int)$share->getShareType():0;
     if($shareType===0&&method_exists($share,'getSharedWith')&&is_string($share->getSharedWith())&&$share->getSharedWith()!==''){
      $affectedUsers[$share->getSharedWith()]=true;
     } elseif($shareType!==0) {
      // Removed group/public/federated shares may no longer enumerate former recipients.
      $this->changes->catalog();
      return;
     }
    }
   }

   if(!empty($affectedUsers)){
    if($isStructural||empty($fileIds)){
     // Structural/folder/share change: account revision mutation triggers catalog sweep
     foreach(array_keys($affectedUsers) as $uid){
      $this->changes->account($uid);
     }
    } else {
     // Ordinary file write/create/delete: record dirty file markers WITHOUT mutating account revision
     foreach(array_keys($affectedUsers) as $uid){
      foreach($fileIds as $id){
       $this->changes->file($uid,$id);
      }
     }
    }
    return;
   }
  }catch(\Throwable){} // Deleted nodes or untracked events fallback conservatively to catalog.

  $this->changes->catalog();
 }
}
