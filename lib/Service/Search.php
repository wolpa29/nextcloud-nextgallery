<?php
declare(strict_types=1);
namespace OCA\Chronicle\Service;
use OCP\Files\Search\{ISearchQuery,ISearchComparison,ISearchOrder,ISearchBinaryOperator};
/** Implement the public search interfaces rather than depending on OC private classes. */
class Search {
 public static function query(int $after,int $limit): ISearchQuery {
  $op=new class($after) implements ISearchComparison {
   public function __construct(private int $after) {}
   public function getType():string{return self::COMPARE_GREATER_THAN;}
   public function getField():string{return 'fileid';}
   public function getValue():int{return $this->after;}
   public function getExtra():string{return '';}
   public function getQueryHint(string $name,$default){return $default;}
   public function setQueryHint(string $name,$value):void{}
  };
  $order=new class implements ISearchOrder {
   public function getDirection():string{return self::DIRECTION_ASCENDING;}
   public function getField():string{return 'fileid';}
   public function getExtra():string{return '';}
   public function sortFileInfo(\OCP\Files\FileInfo $a,\OCP\Files\FileInfo $b):int{return $a->getId()<=>$b->getId();}
  };
  return new class($op,$order,$limit) implements ISearchQuery {
   public function __construct(private $op,private $order,private int $limit){}
   public function getSearchOperation(){return $this->op;}
   public function getLimit(){return $this->limit;}
   public function getOffset(){return 0;}
   public function getOrder(){return [$this->order];}
   public function getUser(){return null;}
   public function limitToHome():bool{return false;}
  };
 }
}
