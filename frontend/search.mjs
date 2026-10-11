/** Render text before starting inference; cancellation also guards against late responses. */
export function progressiveSearch({request,show,timeout=22000}){
 let generation=0,active=null,timer=null;
 function cancel(){generation++;active?.abort();active=null;clearTimeout(timer);timer=null;}
 async function run(params){
  cancel();const own=generation,controller=new AbortController();active=controller;
  const current=()=>own===generation;
  let text;
  try{
   text=await request({...params,phase:'text'},controller.signal);
   if(!current())return;
   await show(text,{stage:'text',current});
  }catch(error){if(!current())return;throw error;}
  if(!current()||text.embedding_state!=='pending')return;
  async function enrich(){
   // Race the request too: even a transport that ignores AbortSignal cannot hold the UI open.
   let deadline,onAbort;
   const expired=new Promise((_,reject)=>{
    onAbort=()=>reject(new Error('Embedding search cancelled'));
    controller.signal.addEventListener('abort',onAbort,{once:true});
    deadline=setTimeout(()=>controller.abort(),timeout);timer=deadline;
   });
   try{
    const result=await Promise.race([request({...params,phase:'hybrid'},controller.signal),expired]);
    if(current())await show(result,{stage:'hybrid',current});
   }catch{
    if(current())await show({...text,embedding_state:'unavailable'},{stage:'fallback',current});
   }finally{clearTimeout(deadline);controller.signal.removeEventListener('abort',onAbort);if(timer===deadline)timer=null;}
  }
  return {finished:enrich()};
 }
 return {run,cancel};
}
