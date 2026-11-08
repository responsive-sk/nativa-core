
(function(){
    var title=document.getElementById('title');
    var slug=document.getElementById('slug');
    var userEdited=false;
    slug.addEventListener('input',function(){userEdited=true});
    title.addEventListener('input',function(){
        if(!userEdited){
            var s=title.value.toLowerCase()
                .replace(/[^a-z0-9\s-]/g,'')
                .replace(/\s+/g,'-')
                .replace(/-+/g,'-')
                .replace(/^-|-$/g,'');
            slug.value=s;
        }
    });
    var img=document.getElementById('image');
    var prev=document.getElementById('image-preview');
    var prevImg=document.getElementById('image-preview-img');
    if(img&&prev&&prevImg){
        if(img.value){prevImg.src=img.value;prev.style.display='block'}
        img.addEventListener('input',function(){
            if(img.value){prevImg.src=img.value;prev.style.display='block'}
            else{prev.style.display='none'}
        });
    }
})();
