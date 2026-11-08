
(function(){
    var toggle=document.getElementById('author_custom_toggle');
    var select=document.getElementById('author_email');
    var row=document.getElementById('author-custom-row');
    if(!toggle||!select||!row){return}
    var input=document.getElementById('author_email_custom');
    toggle.addEventListener('change',function(){
        var custom=toggle.checked;
        select.style.display=custom?'none':'';
        row.style.display=custom?'flex':'none';
        select.disabled=custom;
        if(input){input.disabled=!custom;if(custom){input.value=select.value;input.focus()}}
    });
})();
