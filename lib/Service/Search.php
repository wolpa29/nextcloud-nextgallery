<?php
declare(strict_types=1);
namespace OCA\Nextgallery\Service;
use OCP\Files\Search\{ISearchQuery,ISearchComparison,ISearchOrder,ISearchBinaryOperator};
/** Implement the public search interfaces rather than depending on OC private classes. */
class Search {
 public static function query(int $offset,int $limit): ISearchQuery {
  $comparison=static fn(string $value)=>new class($value) implements ISearchComparison {
   public function __construct(private string $value){}
   public function getType():string{return self::COMPARE_LIKE;}
   public function getField():string{return 'mimetype';}
   public function getValue():string{return $this->value;}
   public function getExtra():string{return '';}
   public function getQueryHint(string $name,$default){return $default;}
   public function setQueryHint(string $name,$value):void{}
   public function __toString():string{return 'mimetype like '.json_encode($this->value);}
  };
  $op=new class([$comparison('image/%'),$comparison('video/%')]) implements ISearchBinaryOperator {
   public function __construct(private array $arguments){}
   public function getType():string{return self::OPERATOR_OR;}
   public function getArguments():array{return $this->arguments;}
   public function getQueryHint(string $name,$default){return $default;}
   public function setQueryHint(string $name,$value):void{}
   public function __toString():string{return 'or('.implode(',',$this->arguments).')';}
  };
  $order=new class implements ISearchOrder {
   public function getDirection():string{return self::DIRECTION_ASCENDING;}
   public function getField():string{return 'fileid';}
   public function getExtra():string{return '';}
   public function sortFileInfo(\OCP\Files\FileInfo $a,\OCP\Files\FileInfo $b):int{return $a->getId()<=>$b->getId();}
  };
  return new class($op,$order,$limit,$offset) implements ISearchQuery {
   public function __construct(private $op,private $order,private int $limit,private int $offset){}
   public function getSearchOperation(){return $this->op;}
   public function getLimit(){return $this->limit;}
   public function getOffset(){return $this->offset;}
   public function getOrder(){return [$this->order];}
   public function getUser(){return null;}
   public function limitToHome():bool{return false;}
   // Added in Nextcloud 35; empty selects the normal complete FileInfo.
   public function getSelectFields():array{return [];}
  };
 }
}
