
(function(){
    document.addEventListener('change',function(e){
        if(e.target&&e.target.id==='select-all'){
            var c=e.target.checked;
            document.querySelectorAll('.media-checkbox').forEach(function(b){b.checked=c});
        }
    });
})();
