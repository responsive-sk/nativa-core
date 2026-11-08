/*
 * Upload form — XHR upload with progress.
 *
 * The file is sent as a base64 urlencoded POST field (file_b64): PHP's
 * multipart staging can be broken or tightly limited on shared hosting
 * (UPLOAD_ERR_NO_TMP_DIR / upload_max_filesize), while a urlencoded body
 * is staged by PHP itself and only bound by post_max_size. Server side
 * MediaUploadPayload decodes it and rebuilds a $_FILES entry, so all app
 * checks run unchanged. Plain multipart POST (no JS) keeps working.
 *
 * Failure handling: the server's error page carries a readable message
 * in responseText — extract and show it in the error box instead of only
 * colouring the progress bar red. window.UPLOAD_LIMITS (injected by the
 * template) drives the client-side size guard.
 */
(function(){
    var form=document.getElementById('upload-form');
    var fileInput=document.getElementById('file');
    var progress=document.getElementById('upload-progress');
    var bar=document.getElementById('upload-progress-bar');
    var btn=document.getElementById('upload-btn');
    var errBox=document.getElementById('upload-js-error');
    if(!form||!fileInput)return;
    var limits=window.UPLOAD_LIMITS||{};
    var maxBytes=limits.maxBytes||10*1024*1024;
    function showError(msg){
        if(errBox){errBox.textContent=msg;errBox.style.display='block';}
    }
    function clearError(){
        if(errBox){errBox.textContent='';errBox.style.display='none';}
    }
    function extractError(xhr){
        var text=xhr.responseText||'';
        var match=text.match(/<div class="cba-msg cba-msg--err">([\s\S]*?)<\/div>/);
        if(match&&match[1]){
            var tmp=document.createElement('div');
            tmp.innerHTML=match[1];
            var msg=tmp.textContent||tmp.innerText||'';
            if(msg){return msg;}
        }
        if(xhr.status===413){return 'File exceeds the server upload limit.';}
        if(xhr.status===403){return 'Upload rejected (403) — session expired or the request was blocked by a security filter.';}
        return 'Upload failed (HTTP '+xhr.status+').';
    }
    function toBase64(file,cb){
        var reader=new FileReader();
        reader.onload=function(){cb(String(reader.result).split(',').pop()||'')};
        reader.onerror=function(){cb(null)};
        reader.readAsDataURL(file);
    }
    form.addEventListener('submit',function(e){
        if(!fileInput.files||!fileInput.files[0])return;
        clearError();
        var file=fileInput.files[0];
        if(maxBytes>0&&file.size>maxBytes){
            e.preventDefault();
            showError('File is too large ('+(file.size/(1024*1024)).toFixed(1)+' MB). The server accepts up to '+(maxBytes/(1024*1024))+' MB.');
            return;
        }
        e.preventDefault();
        var fd=new FormData(form);
        if(typeof window.btoa==='function'&&typeof FileReader!=='undefined'){
            toBase64(file,function(b64){
                if(null===b64){fd.delete('file');form.submit();return}
                fd.set('file_b64',b64);
                fd.set('file_b64_name',file.name);
                fd.delete('file');
                send(fd);
            });
            return;
        }
        fd.delete('file');
        form.submit();
    });
    function send(fd){
        var xhr=new XMLHttpRequest();
        xhr.open('POST','/mark/media/upload',true);
        xhr.upload.addEventListener('progress',function(ev){
            if(ev.lengthComputable){
                var pct=Math.round((ev.loaded/ev.total)*100);
                bar.style.width=pct+'%';
                progress.style.display='block';
                btn.textContent='Uploading... '+pct+'%';
                btn.disabled=true;
            }
        });
        xhr.addEventListener('load',function(){
            if(xhr.status>=200&&xhr.status<400){
                window.location.href='/mark/media';
            }else{
                bar.style.width='100%';
                bar.style.background='var(--m-error)';
                btn.textContent='Upload failed';
                btn.disabled=false;
                showError(extractError(xhr));
            }
        });
        xhr.addEventListener('error',function(){
            btn.textContent='Upload error';
            btn.disabled=false;
            showError('Network error during upload.');
        });
        xhr.send(fd);
    }
})();
