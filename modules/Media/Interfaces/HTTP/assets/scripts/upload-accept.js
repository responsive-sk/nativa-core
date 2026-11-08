
(function(){
    var LOCAL='image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/markdown';
    var CLOUD='image/jpeg,image/png,image/gif,image/webp';
    var inp=document.getElementById('file');
    function updateAccept(){
        if(!inp)return;
        var prov=document.querySelector('input[name="provider"]:checked');
        inp.accept=prov&&prov.value==='cloudinary'?CLOUD:LOCAL;
    }
    document.getElementById('provider-local').addEventListener('change',updateAccept);
    document.getElementById('provider-cloudinary').addEventListener('change',updateAccept);
})();
