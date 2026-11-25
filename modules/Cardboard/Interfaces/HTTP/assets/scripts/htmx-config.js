(function(){
    var s = document.currentScript;
    var token = (s && s.getAttribute("data-csrf-token")) || "";
    document.addEventListener("DOMContentLoaded", function(){
        if (typeof htmx === "undefined") return;
        document.body.addEventListener("htmx:configRequest", function(e){
            if (token) { e.detail.headers["X-CSRF-Token"] = token; }
        });
        htmx.config.defaultSwapStyle = "innerHTML";
        htmx.config.historyEnabled = true;
    });
})();
