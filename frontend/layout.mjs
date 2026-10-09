export function layout(groups,width,target=180,headers=true,fixedColumns=0) {
 const gap=4,columns=fixedColumns?Math.max(2,Math.min(6,fixedColumns)):Math.max(2,Math.floor((width+gap)/(target+gap))),size=(width-(columns-1)*gap)/columns;
 let y=0;const sections=groups.map(g=>{const start=y,header=headers?64:20;const rows=Math.ceil(g.count/columns);y+=header+rows*(size+gap);return {...g,start,end:y,header,rows};});
 return {sections,height:y,columns,size,gap};
}
export function visible(layout,top,height,overscan=400) {
 const min=Math.max(0,top-overscan),max=top+height+overscan,result=[];
 for(const s of layout.sections) {if(s.end<min||s.start>max)continue;
  if(s.start+s.header>=min)result.push({key:`h:${s.key}`,type:'header',section:s,y:s.start,height:s.header});
  const first=Math.max(0,Math.floor((min-s.start-s.header)/(layout.size+layout.gap)));
  const last=Math.min(s.rows-1,Math.floor((max-s.start-s.header)/(layout.size+layout.gap)));
  for(let r=first;r<=last;r++)result.push({key:`r:${s.key}:${r}`,type:'row',section:s,row:r,y:s.start+s.header+r*(layout.size+layout.gap),height:layout.size});
 }return result;
}
export function groupKey(item,grouping) {const p=item.date.period;return grouping==='year'?p.slice(0,4):grouping==='day'?p:p.slice(0,7);}
export function localSummary(items,settings,kind='') {
 const filtered=items.filter(i=>!kind||i.kind===kind).sort((a,b)=>{const n=a.date.sort.localeCompare(b.date.sort)||a.id-b.id;return settings.order==='asc'?n:-n;});
 const groups=new Map();for(const i of filtered){const key=groupKey(i,settings.grouping);if(!groups.has(key))groups.set(key,{key,count:0});groups.get(key).count++;}
 return {items:filtered,groups:[...groups.values()],total:filtered.length,videos:filtered.filter(i=>i.kind==='video').length,settings};
}
