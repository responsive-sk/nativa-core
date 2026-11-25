const f=document.querySelector("[data-mermaid]");
if(f){
if("IntersectionObserver" in window){
const io=new IntersectionObserver((es)=>{
if(!es.some((e)=>e.isIntersecting))return;
io.disconnect();
import(f.dataset.diagramChunk).catch(()=>{f.hidden=true});
},{rootMargin:"300px"});
io.observe(f);}else{f.hidden=true}}
