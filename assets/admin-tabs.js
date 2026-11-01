/* Keep settings and diagnostics navigation from interrupting a running migration. */
(() => {
    'use strict';
    const panels=[...document.querySelectorAll('[data-csm-view]')];
    const links=[...document.querySelectorAll('[data-csm-tab]')];
    function show(view, navigate=false) {
        if(!panels.some(panel=>panel.dataset.csmView===view))view='migrate';
        panels.forEach(panel=>{panel.hidden=panel.dataset.csmView!==view;});
        links.forEach(link=>{
            if(!link.closest('.csm-tabs'))return;
            const active=link.dataset.csmTab===view;
            link.classList.toggle('nav-tab-active',active);
            if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
        });
        if(navigate)history.pushState(null,'',links.find(link=>link.dataset.csmTab===view).href);
        if(view==='diagnostics'){
            const logs=document.getElementById('csm-diagnostics');
            logs.open=true;logs.dispatchEvent(new Event('toggle'));
        }
    }
    links.forEach(link=>link.addEventListener('click',event=>{
        if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||link.dataset.csmTab==='backups')return;
        event.preventDefault();show(link.dataset.csmTab,true);
    }));
    window.addEventListener('popstate',()=>show(new URL(location.href).searchParams.get('view')||'migrate'));
    show(new URL(location.href).searchParams.get('view')||'migrate');
})();
