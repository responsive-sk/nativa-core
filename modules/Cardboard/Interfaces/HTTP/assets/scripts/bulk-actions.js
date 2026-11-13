
(function(){
    var form=document.getElementById('bulk-delete-form');
    if(!form)return;
    form.addEventListener('submit',function(e){
        var checked=document.querySelectorAll('.m-bulk__check:checked');
        if(!checked.length){e.preventDefault();return}
        var total=checked.length;
        var publishedCount=0;
        var names=[];
        checked.forEach(function(cb){
            var tr=cb.closest('tr');
            if(!tr)return;
            /* Articles render the Publish/Draft switch (.m-pubswitch--on =
               published); pages still use the Published badge — cover both. */
            var sw=tr.querySelector('.m-pubswitch');
            var badge=tr.querySelector('.m-badge-success');
            var isPublished=(sw&&sw.classList.contains('m-pubswitch--on'))
                ||(badge&&badge.textContent.indexOf('Published')!==-1);
            if(isPublished){
                publishedCount++;
                var titleEl=tr.querySelector('strong');
                if(titleEl&&names.length<3)names.push(titleEl.textContent.trim());
            }
        });
        var msg='';
        if(publishedCount>0){
            msg=publishedCount+' of '+total+' selected items are published:\n';
            names.forEach(function(n){msg+='  \u2022 '+n+'\n'});
            if(total>3)msg+='  and '+(total-3)+' more\n';
            msg+='\nThey will be unpublished first, then deleted.\n\nContinue?';
        }else{
            msg='Delete '+total+' items?';
        }
        if(!confirm(msg)){e.preventDefault()}
    });
})();
