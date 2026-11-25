(function(){
    var modal=document.getElementById('media-picker-modal');
    var limits={};
    try{limits=JSON.parse((modal&&modal.getAttribute('data-upload-limits'))||'{}');}catch(e){}
    window.UPLOAD_LIMITS=window.UPLOAD_LIMITS||limits;
    var _pickerTarget='';
    var _pickerMode='insert';
    var _textareaId=(modal&&modal.getAttribute('data-textarea-id'))||'';
    var _textarea=_textareaId?document.getElementById(_textareaId):null;
    var _csrfInput=document.querySelector('input[name="_token"]');
    var _csrfToken=_csrfInput?_csrfInput.value:'';

    /* ── Media picker modal ── */
    function openMediaPicker(targetId,mode){
        _pickerTarget=targetId||'';
        _pickerMode=mode||'insert';
        document.getElementById('media-picker-modal').style.display='block';
        loadPickerGrid();
    }
    function closeMediaPicker(){
        document.getElementById('media-picker-modal').style.display='none';
    }
    function loadPickerGrid(){
        var grid=document.getElementById('media-picker-grid');
        grid.innerHTML='<p style="color:var(--cba-muted);font-size:13px;">Loading...</p>';
        fetch(modal.getAttribute('data-index-url')).then(function(r){return r.json()}).then(function(data){
            grid.innerHTML='';
            if(!data.length){grid.innerHTML='<p style="color:var(--cba-muted);font-size:13px;">No images uploaded yet. Upload one above.</p>';return}
            data.forEach(function(item){
                if(item.mime_type.indexOf('image/')!==0)return;
                var div=document.createElement('div');
                div.style.cssText='cursor:pointer;border:2px solid transparent;border-radius:8px;overflow:hidden;transition:border-color .15s;';
                div.addEventListener('mouseover',function(){div.style.borderColor='var(--cba-accent-strong)'});
                div.addEventListener('mouseout',function(){div.style.borderColor='transparent'});
                div.addEventListener('click',function(){
                    if(_pickerMode==='fill'&&_pickerTarget){
                        var el=document.getElementById(_pickerTarget);
                        if(el){el.value=item.url}
                        var prev=document.getElementById('image-preview');
                        var prevImg=document.getElementById('image-preview-img');
                        if(prev&&prevImg){prevImg.src=item.url;prev.style.display='block'}
                    }else if(_textarea){
                        insertAtCursor(_textarea,'!['+item.original_name+']('+item.url+')');
                    }
                    closeMediaPicker();
                });
                div.innerHTML='<img src="'+item.url+'" alt="'+item.original_name+'" style="width:100%;height:100px;object-fit:cover;display:block;"><div style="padding:6px 8px;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+item.original_name+'</div>';
                grid.appendChild(div);
            });
        }).catch(function(){grid.innerHTML='<p style="color:var(--cba-err-ink);font-size:13px;">Failed to load images.</p>'});
    }

    /* ── Inline upload from picker ── */
    document.querySelector('[data-action="upload-inline"]').addEventListener('click',function(){
        document.getElementById('media-inline-file').click();
    });
    document.getElementById('media-inline-file').addEventListener('change',function(e){
        var file=e.target.files&&e.target.files[0];
        if(!file)return;
        uploadFile(file,function(item){
            if(_textarea){insertAtCursor(_textarea,'!['+item.original_name+']('+item.url+')')}
            loadPickerGrid();
        });
    });

    /* ── Drag-drop on textarea ── */
    if(_textarea){
        _textarea.addEventListener('dragover',function(e){e.preventDefault();_textarea.style.borderColor='var(--cba-accent-strong)';_textarea.style.boxShadow='0 0 0 2px var(--cba-accent-soft)'});
        _textarea.addEventListener('dragleave',function(){_textarea.style.borderColor='';_textarea.style.boxShadow=''});
        _textarea.addEventListener('drop',function(e){
            e.preventDefault();
            _textarea.style.borderColor='';
            _textarea.style.boxShadow='';
            var files=e.dataTransfer&&e.dataTransfer.files;
            if(!files||!files.length)return;
            for(var i=0;i<files.length;i++){
                if(files[i].type.indexOf('image/')===0){
                    (function(f){
                        uploadFile(f,function(item){
                            insertAtCursor(_textarea,'!['+item.original_name+']('+item.url+')');
                        });
                    })(files[i]);
                }
            }
        });

        /* ── Clipboard paste ── */
        _textarea.addEventListener('paste',function(e){
            var items=e.clipboardData&&e.clipboardData.items;
            if(!items)return;
            for(var i=0;i<items.length;i++){
                if(items[i].type.indexOf('image/')===0){
                    e.preventDefault();
                    var file=items[i].getAsFile();
                    if(file){uploadFile(file,function(item){
                        insertAtCursor(_textarea,'!['+item.original_name+']('+item.url+')');
                    })}
                    return;
                }
            }
        });
    }

    /* ── Upload helper ── */
    function uploadFile(file,onSuccess){
        var limits=window.UPLOAD_LIMITS||{};
        var maxBytes=limits.maxBytes||10*1024*1024;
        if(maxBytes>0&&file.size>maxBytes){
            alert('File is too large ('+(file.size/(1024*1024)).toFixed(1)+' MB). The server accepts up to '+(maxBytes/(1024*1024))+' MB.');
            return;
        }
        var fd=new FormData();
        fd.append('_token',_csrfToken);
        function send(){
            fetch(modal.getAttribute('data-upload-url'),{method:'POST',body:fd}).then(function(r){
                return r.json()['catch'](function(){return {error:'Upload failed (HTTP '+r.status+') — the file may exceed the server upload limit or was rejected by a security filter.'}})
            }).then(function(data){
                if(data.error){alert('Upload failed: '+data.error);return}
                if(onSuccess)onSuccess(data);
            }).catch(function(){alert('Upload failed.')});
        }
        if(typeof window.btoa==='function'&&typeof FileReader!=='undefined'){
            var reader=new FileReader();
            reader.onload=function(){
                fd.append('file_b64',String(reader.result).split(',').pop()||'');
                fd.append('file_b64_name',file.name);
                send();
            };
            reader.onerror=function(){
                fd.append('file',file);
                send();
            };
            reader.readAsDataURL(file);
            return;
        }
        fd.append('file',file);
        send();
    }

    /* ── Textarea insert helper ── */
    function insertAtCursor(el,text){
        var start=el.selectionStart;
        var end=el.selectionEnd;
        var before=el.value.substring(0,start);
        var after=el.value.substring(end);
        var pad=(before.length>0&&before[before.length-1]!=='\n'&&(before.length<2||before.substring(before.length-2)!=='\n\n'))?'\n\n':'';
        el.value=before+pad+text+after;
        el.selectionStart=el.selectionEnd=start+pad.length+text.length;
        el.focus();
    }

    /* ── Expose globals ── */
    window.openMediaPicker=openMediaPicker;
    var closeBtn=document.querySelector('[data-action="close-media-picker"]');
    if(closeBtn)closeBtn.addEventListener('click',closeMediaPicker);
    document.querySelectorAll('[data-action="open-media-picker"]').forEach(function(btn){
        btn.addEventListener('click',function(){openMediaPicker(btn.dataset.target,'fill')});
    });
})();
