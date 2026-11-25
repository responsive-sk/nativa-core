(function(){
    var s = document.currentScript;
    var el = s && s.previousElementSibling;
    if (!el || !el.hasAttribute("data-toast-message")) return;
    var message = el.getAttribute("data-toast-message");
    var kind = el.getAttribute("data-toast-kind") || "ok";
    function fire() { if (typeof window.mToast === "function") { window.mToast(message, kind); } }
    if (typeof window.mToast === "function") { fire(); } else { document.addEventListener("DOMContentLoaded", fire); }
})();
