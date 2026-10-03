<?php
require __DIR__.'/../lib/Service/Dates.php';
use OCA\Chronicle\Service\Dates;
$n=0;
function check($actual,$expected):void {global $n;$n++;if($actual!==$expected)throw new RuntimeException('Assertion '.$n.': '.json_encode([$actual,$expected]));}
$r=Dates::resolve('/Camera/2024/08/random.jpg','2026:01:12 16:00:00',1700000000,'folder','Europe/Vienna');check($r['date'],'2024-08');check($r['precision'],'month');check($r['conflict'],true);
$r=Dates::resolve('/Camera/2024/08/IMG_20240812_163000.jpg',null,1700000000,'folder','Europe/Vienna');check($r['date'],'2024-08-12');check($r['source'],'folder+filename');
check(Dates::folder('/Camera/2023/Dezember/a.jpg')['date'],'2023-12');check(Dates::folder('/Camera/2024-08/a.jpg')['precision'],'month');check(Dates::folder('/Camera/2022/a.jpg')['precision'],'year');
check(Dates::folder('/Camera/2024/08/17/a.jpg')['date'],'2024-08-17');check(Dates::name('PXL_20240229_235959.jpg')['date'],'2024-02-29');check(Dates::name('IMG_20230229_120000.jpg'),null);check(Dates::name('IMG_20241301.jpg'),null);
check(Dates::metadata('2024:02:31 12:00:00','UTC'),null);check(Dates::metadata('2024:01:31 23:30:00-02:00','Europe/Vienna')['date'],'2024-02-01');
check(Dates::resolve('/2020/01/a.jpg','2025:05:18 10:00:00',1700000000,'metadata','UTC')['date'],'2025-05-18');check(Dates::resolve('/2020/01/IMG_20230816.jpg','2025:05:18 10:00:00',1700000000,'filename','UTC')['date'],'2023-08-16');
check(Dates::resolve('/undated/a.jpg',null,1700000000,'folder','UTC')['source'],'mtime');
check(Dates::gps(['GPSLatitude'=>['0/1','0/1','0/1'],'GPSLongitude'=>['0/1','0/1','0/1'],'GPSLatitudeRef'=>'N','GPSLongitudeRef'=>'E']),[0.0,0.0]);check(Dates::gps(['GPSLatitude'=>['20/0','0/1','0/1'],'GPSLongitude'=>['0/1','0/1','0/1']]),null);
echo "$n date/GPS assertions passed\n";
