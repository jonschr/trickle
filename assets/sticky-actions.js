/* Only the background expands when the action bar reaches its sticky position. */
(() => {
    'use strict';
    const actions=document.querySelector('.csm-actions');
    if(!actions||!globalThis.IntersectionObserver)return;
    const sentinel=document.createElement('div');sentinel.className='csm-sticky-sentinel';sentinel.setAttribute('aria-hidden','true');actions.before(sentinel);
    const top=parseFloat(getComputedStyle(actions).top)||0;
    const update=rect=>{
        const sticky=getComputedStyle(actions).position==='sticky';
        actions.classList.toggle('is-stuck',sticky&&actions.getClientRects().length>0&&rect.bottom<=top);
    };
    const observer=new IntersectionObserver(([entry])=>update(entry.boundingClientRect),{rootMargin:`-${top}px 0px 0px 0px`,threshold:0});
    observer.observe(sentinel);update(sentinel.getBoundingClientRect());
})();
