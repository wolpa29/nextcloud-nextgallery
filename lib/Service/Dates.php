<?php
declare(strict_types=1);
namespace OCA\Chronicle\Service;
use DateTimeImmutable;
use DateTimeZone;
/** Dates retain precision; a month folder never claims an invented capture day. */
class Dates {
 private static function result(int $y,int $m=0,int $d=0,string $time='000000',string $source='folder'): array {
  return ['date'=>sprintf('%04d',$y).($m?sprintf('-%02d',$m):'').($d?sprintf('-%02d',$d):''),
   'precision'=>$d?'day':($m?'month':'year'),'source'=>$source,
   'period'=>sprintf('%04d-%02d-%02d',$y,$m,$d),'sort'=>sprintf('%04d%02d%02d',$y,$m,$d).$time];
 }
 public static function folder(string $path): ?array {
  $parts=explode('/',trim(dirname($path),'/')); $best=null;
  $months=['january'=>1,'januar'=>1,'february'=>2,'februar'=>2,'march'=>3,'märz'=>3,'maerz'=>3,'april'=>4,'may'=>5,'mai'=>5,'june'=>6,'juni'=>6,'july'=>7,'juli'=>7,'august'=>8,'september'=>9,'october'=>10,'oktober'=>10,'november'=>11,'december'=>12,'dezember'=>12];
  foreach ($parts as $i=>$part) {
   if (preg_match('/^((?:19|20|21)\d{2})(?:[-_.](0?[1-9]|1[0-2]))?(?:[-_.](0?[1-9]|[12]\d|3[01]))?$/u',$part,$a)) {
    $y=(int)$a[1];$m=(int)($a[2]??0);$d=(int)($a[3]??0);
    if (!$m && isset($parts[$i+1])) {
     $next=mb_strtolower($parts[$i+1]);
     if (preg_match('/^(0?[1-9]|1[0-2])(?:[ _.-].*)?$/u',$next,$b)) $m=(int)$b[1];
     else $m=$months[$next]??0;
     if ($m && isset($parts[$i+2]) && preg_match('/^(0?[1-9]|[12]\d|3[01])$/',$parts[$i+2])) $d=(int)$parts[$i+2];
    }
    if (!$d || ($m && checkdate($m,$d,$y))) $best=self::result($y,$m,$d);
   }
  }
  return $best;
 }
 public static function name(string $name): ?array {
  if (!preg_match('/(?<!\d)((?:19|20|21)\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])(?:[T _.-]([0-2]\d)[-:]?([0-5]\d)[-:]?([0-5]\d))?(?!\d)/',$name,$a)) return null;
  if (!checkdate((int)$a[2],(int)$a[3],(int)$a[1]) || (int)($a[4]??0)>23) return null;
  return self::result((int)$a[1],(int)$a[2],(int)$a[3],($a[4]??'00').($a[5]??'00').($a[6]??'00'),'filename');
 }
 public static function metadata(?string $raw,string $zone): ?array {
  if (!$raw || !preg_match('/^(\d{4})[:-](\d{2})[:-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})(.*)$/',$raw,$a)) return null;
  if (!checkdate((int)$a[2],(int)$a[3],(int)$a[1]) || (int)$a[4]>23 || (int)$a[5]>59 || (int)$a[6]>59) return null;
  try {
   $dt=new DateTimeImmutable("$a[1]-$a[2]-$a[3]T$a[4]:$a[5]:$a[6]$a[7]",new DateTimeZone($zone));
   $dt=$dt->setTimezone(new DateTimeZone($zone));
   return self::result((int)$dt->format('Y'),(int)$dt->format('m'),(int)$dt->format('d'),$dt->format('His'),'metadata');
  } catch (\Throwable) {return null;}
 }
 public static function resolve(string $path,?string $raw,int $mtime,string $mode,string $zone): array {
  $f=self::folder($path);$n=self::name(basename($path));$e=self::metadata($raw,$zone);
  $dt=(new DateTimeImmutable('@'.$mtime))->setTimezone(new DateTimeZone($zone));
  $fallback=self::result((int)$dt->format('Y'),(int)$dt->format('m'),(int)$dt->format('d'),$dt->format('His'),'mtime');
  $r=match($mode) {'metadata'=>$e??$n??$f??$fallback,'filename'=>$n??$e??$f??$fallback,default=>$f??$e??$n??$fallback};
  // More precise evidence may refine a folder date only when its year/month agree.
  if ($mode==='folder' && $f && $f['precision']!=='day') {
   $detail=$e??$n;
   if ($detail && str_starts_with($detail['date'],$f['date'])) {$r=$detail;$r['source']='folder+'.$detail['source'];}
  }
  $r['conflict']=$f && ($e??$n) && !str_starts_with(($e??$n)['date'],$f['date']);
  return $r;
 }
 public static function gps(array $exif): ?array {
  $g=$exif['GPS']??$exif;
  $number=static function($v): float { if (is_numeric($v)) return (float)$v; $p=explode('/',(string)$v);return count($p)===2 && (float)$p[1]!==0.0?(float)$p[0]/(float)$p[1]:NAN;};
  $parse=static function($v,$ref) use($number): float { if (!is_array($v)||count($v)!==3) return NAN;return (in_array($ref,['S','W'],true)?-1:1)*($number($v[0])+$number($v[1])/60+$number($v[2])/3600);};
  $lat=$parse($g['GPSLatitude']??null,$g['GPSLatitudeRef']??'');$lng=$parse($g['GPSLongitude']??null,$g['GPSLongitudeRef']??'');
  return is_finite($lat)&&is_finite($lng)&&abs($lat)<=90&&abs($lng)<=180?[$lat,$lng]:null;
 }
}
