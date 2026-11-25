(function(){
    var s=document.currentScript;
        var img=document.getElementById(s.getAttribute('data-target'));
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
