/*
 * Article form guard — base64-encodes the Markdown body into "body_b64"
 * right before submit. Hosting-level WAF rules sometimes misread shell
 * snippets in article bodies ("curl … | bash", "sudo pacman -S") as an
 * attack and reject the whole POST before it reaches PHP; the firewall
 * sees only base64 letters instead. Server-side ArticleFormPayload
 * decodes it back; a plain "body" POST (JS disabled) still works.
 */
(function(){
    var form = document.getElementById('body') ? document.getElementById('body').form : null;
    if (!form) { return; }

    var done = false;
    form.addEventListener('submit', function(){
        if (done) { return; }
        done = true;
        var body = document.getElementById('body');
        var encoded = document.createElement('input');
        encoded.type = 'hidden';
        encoded.name = 'body_b64';
        encoded.value = btoa(unescape(encodeURIComponent(body.value)));
        form.appendChild(encoded);
        body.disabled = false;
        body.removeAttribute('name');
    });
})();
