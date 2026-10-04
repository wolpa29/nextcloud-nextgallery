import {tr} from './i18n.mjs';
import L from 'leaflet';
import 'leaflet.markercluster';
import world from './world.json';
window.ChronikMap={create(host,onOpen,imageURL){
 const map=L.map(host,{minZoom:0,maxZoom:16,zoomControl:true,attributionControl:true,worldCopyJump:false,maxBounds:[[-85,-220],[85,220]]});
 const reset=()=>map.fitBounds([[-60,-180],[84,180]],{padding:[12,12]});reset();
 L.geoJSON(world,{style:{fillColor:'#fcfcf8',fillOpacity:1,color:'#c4cfd5',weight:.65},interactive:false}).addTo(map);
 const clusters=L.markerClusterGroup({chunkedLoading:true,maxClusterRadius:45,showCoverageOnHover:false,spiderfyOnMaxZoom:true});map.addLayer(clusters);
 map.attributionControl.addAttribution('Natural Earth · Public domain');
 const all=[];
 return {add(items){all.push(...items);for(const item of items){const gps=item.metadata.gps;if(!gps)continue;const pin=L.marker(gps,{icon:L.divIcon({className:'ch-map-pin',iconSize:[15,15],iconAnchor:[7,7]})});
  const popup=document.createElement('div');popup.className='ch-map-popup';const img=document.createElement('img');img.alt='';const title=document.createElement('strong');title.textContent=item.date.date;const button=document.createElement('button');button.textContent=tr('Medium öffnen');button.onclick=()=>onOpen(item,all);popup.append(img,title,button);pin.bindPopup(popup);pin.on('popupopen',()=>imageURL(item).then(url=>{if(url)img.src=url;}));clusters.addLayer(pin);
 }},reset,remove(){map.remove();}};
}};
