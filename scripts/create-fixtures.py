#!/usr/bin/env python3
"""Create clearly marked synthetic landscapes, EXIF dates, GPS and H.264 video.
Never reads personal media. Requires Pillow and system FFmpeg."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
from PIL.TiffImagePlugin import IFDRational
import math,random,subprocess,json,hashlib
out=Path('fixtures');out.mkdir(exist_ok=True)
font=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',22)
places=[(47.07,15.44),(48.21,16.37),(46.62,14.31),(45.43,12.34),(35.68,139.69),(-33.87,151.21),(64.15,-21.94),(40.71,-74.0),(0.0,0.0)]
colors=[((193,214,228),(110,149,168),(45,87,115)),((242,211,179),(210,169,142),(108,114,123)),((196,220,211),(103,155,131),(36,99,104)),((221,213,229),(161,151,187),(77,87,142)),((205,220,227),(141,158,149),(83,105,82))]
def gps(v):
 a=abs(v);d=int(a);m=int((a-d)*60);s=((a-d)*60-m)*60
 return IFDRational(d,1),IFDRational(m,1),IFDRational(int(s*1000),1000)
def landscape(seed,caption):
 rng=random.Random(seed);sky,mountain,water=colors[seed%len(colors)];im=Image.new('RGB',(960,720));draw=ImageDraw.Draw(im)
 for y in range(720):
  t=y/720;c=tuple(int(v*(1-t)+water[i]*t) for i,v in enumerate(sky));draw.line((0,y,960,y),fill=c)
 draw.ellipse((630,100,720,190),fill=(249,241,219))
 for depth in range(3):
  pts=[(-20,470+depth*25)]+[(x,rng.randint(220+depth*60,380+depth*50)) for x in range(-20,1050,140)]+[(1000,720),(-20,720)]
  color=tuple(max(0,int(c*(1-depth*.16))) for c in mountain);draw.polygon(pts,fill=color)
 draw.polygon([(0,510),(960,470),(960,720),(0,720)],fill=water)
 for _ in range(38):
  x=rng.randint(0,960);y=rng.randint(500,700);w=rng.randint(25,170);draw.line((x,y,x+w,y),fill=tuple(min(255,c+25) for c in water),width=2)
 if seed%3==0:
  draw.polygon([(400,570),(460,577),(445,595),(415,595)],fill=(42,55,63));draw.line((433,577,433,489),fill=(239,240,234),width=3);draw.polygon([(430,497),(430,568),(375,568)],fill=(241,233,220))
 if seed%3==1:
  for x in [50,97,140,818,865]:
   draw.rectangle((x,500,x+4,550),fill=(40,69,64));draw.polygon([(x-24,515),(x+2,400),(x+30,515)],fill=(36,75,72))
 draw.rounded_rectangle((22,660,580,705),radius=8,fill=(18,29,39));draw.text((37,670),'DEMO · '+caption,font=font,fill=(242,242,242))
 return im
count=0
for year in range(2022,2027):
 for month in [1,3,6,8,10,12]:
  if year==2026 and month>10:continue
  for i in range(10):
   count+=1;day=4+i;path=out/str(year)/f'{month:02d}';path.mkdir(parents=True,exist_ok=True)
   name=f'IMG_{year}{month:02d}{day:02d}_{10+i:02d}3000_DEMO.jpg'
   exif=Image.Exif();exif[36867]=f'{year}:{month:02d}:{day:02d} {10+i:02d}:30:00'
   if i%3!=2:
    lat,lng=places[(year+month+i)%len(places)];exif[34853]={1:'N' if lat>=0 else 'S',2:gps(lat),3:'E' if lng>=0 else 'W',4:gps(lng)}
   landscape(count,f'{year} / {month:02d}').save(path/name,quality=86,exif=exif)
# Missing day, year only, conflict and metadata/name priority cases.
for path,raw in [('2021/07/month_only_DEMO.jpg',None),('2020/year_only_DEMO.jpg',None),('2024/08/conflict_DEMO.jpg','2025:01:15 12:00:00'),('undated/metadata_DEMO.jpg','2019:05:12 10:00:00')]:
 p=out/path;p.parent.mkdir(parents=True,exist_ok=True);e=Image.Exif()
 if raw:e[36867]=raw
 landscape(320,path.split('/')[0]).save(p,quality=86,exif=e)
for year,month in [(2026,10),(2023,6)]:
 p=out/str(year)/f'{month:02d}'/f'VID_{year}{month:02d}12_120000_DEMO.mp4';source=next((out/str(year)/f'{month:02d}').glob('*.jpg'))
 subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-y','-loop','1','-i',str(source),'-vf','scale=960:720,zoompan=z=1.03+0.0003*on:d=1:s=960x720:fps=24','-t','3','-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart','-metadata',f'creation_time={year}-{month:02d}-12T10:00:00Z',str(p)],check=True)
manifest={str(p.relative_to(out)):hashlib.sha256(p.read_bytes()).hexdigest() for p in out.rglob('*') if p.is_file()}
Path('artifacts').mkdir(exist_ok=True);Path('artifacts/fixture-hashes.json').write_text(json.dumps(manifest,indent=2));print(f'{len(manifest)} synthetic media, {sum(p.stat().st_size for p in out.rglob("*") if p.is_file())/1048576:.1f} MB')
