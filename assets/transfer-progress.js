/* Presentation only: uses existing checkpoints, progress snapshots and diagnostic entries. */
(() => {
    'use strict';
    const names = {database:'Database', plugins:'Plugins', themes:'Themes', media:'Media'};
    const size = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KiB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MiB` : `${(n / 1073741824).toFixed(2)} GiB`;
    const duration = seconds => {
        seconds = Math.max(0, Math.round(seconds));
        const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
        return h ? `${h}h ${m}m` : m ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
    };
    const labels = {preflight:'Preparing streams',transfer:'Transferring',verify:'Verifying staged data',apply:'Applying changes',recover:'Recovering transfer',complete:'Complete',restored:'Destination restored',failed:'Stopped'};
    class TransferProgress {
        constructor(root, clock = () => Date.now()) {
            this.root = root; this.clock = clock; this.events = []; this.phase = 'preflight'; this.frozen = null;
            root?.addEventListener('click', event => {
                const copy=event.target.closest('[data-copy-event]');
                if(copy)this.copyEvent(copy.dataset.copyEvent,copy);
            });

        }
        async copyEvent(id, button) {
            const item=this.events.find(item=>item.id===id);if(!item)return;
            try { await navigator.clipboard.writeText([item.phase,item.component,item.error||item.failure?.error].filter(Boolean).join(' · '));button.textContent='Copied'; }
            catch (_) { button.textContent='Select error text to copy'; }
        }
        clear() {
            this.timeline=null;
            if(this.root){this.root.hidden=true;const elapsed=this.root.closest('dialog')?.querySelector('[data-transfer-elapsed]');if(elapsed)elapsed.hidden=true;}
        }
        begin(timeline) {
            if (this.timeline?.id === timeline.id) return;
            this.timeline = timeline; this.started = this.clock() - Math.max(0, timeline.elapsed || 0) * 1000;
            this.events = []; this.seen = new Set(); this.logs = new Set(); this.observed = {}; this.verified = new Set();
            this.movement = null;
            this.phase = 'preflight'; this.frozen = null; this.detail = null; this.lastMovement = this.clock(); this.retryCount = 0; this.notice = null; this.lastResolved = 0;
            this.operationName=null;this.staleRuns=new Set();this.replacement={};
            this.componentStructure=null; this.signature = ''; this.eventSignature = null; this.operationKind = null;
            this.resolvedRuns = new Set(); this.restore(); this.render();
        }
        restore() {
            try {
                const saved = JSON.parse(sessionStorage.getItem('trickle-progress') || 'null');
                if (saved?.id === this.timeline.id) {
                    for (const event of saved.events.slice(-80)) this.addEvent(event);
                    this.replacement=saved.replacement || {};
                    this.verified=new Set(saved.verified || []); this.retryCount = saved.retryCount || 0; this.logs = new Set(saved.logs || []);
                }
            } catch (_) { /* Storage is optional. */ }
        }
        operation(op, kind) {
            kind=kind || null;
            if(op!==this.operationName||kind!==this.operationKind){if(this.detail?.run)this.staleRuns.add(this.detail.run);this.detail=null;this.lastMovement=this.clock();}
            this.operationName=op;
            this.operationKind = kind || null;
            this.setPhase(op === 'commit' ? 'verify' : op === 'rollback' ? 'recover' : op === 'preflight' ? 'preflight' : 'transfer');
        }
        setPhase(phase) {
            if (this.phase === phase || this.frozen != null) return;
            this.phase = phase; this.lastMovement = this.clock();
            this.render();
        }
        activeKind() {
            if (this.operationKind && this.timeline?.streams[this.operationKind]) return this.operationKind;
            const streams = Object.entries(this.timeline?.streams || {});
            return streams.find(([key,s]) => s.size == null || this.streamReceived(key) < s.size)?.[0] || streams.at(-1)?.[0];
        }
        streamReceived(kind) {
            const s = this.timeline?.streams[kind];
            return s ? Math.min(s.size ?? Infinity, Math.max(s.received || 0, this.observed?.[kind] || 0)) : 0;
        }
        update(result, phase) {
            const previousBytes = this.receivedBytes();
            const timeline = result?.timeline || result?.progress?.timeline;
            if (timeline) { this.begin(timeline); this.timeline = timeline; }
            this.confirm(result);
            if (phase) this.setPhase(phase);
            if (!this.timeline) return;
            let detail = result?.progress?.phase ? result.progress : result?.phase ? result : null;
            if(detail&&!this.acceptDetail(detail))detail=null;
            if (detail) {
                this.detail = detail;
                const movement = `${detail.phase}:${detail.component}:${detail.count}`;
                if (movement !== this.movement) this.lastMovement = this.clock();
                this.movement = movement;
                const kind = this.activeKind(), stream = this.timeline?.streams[kind];
                if (this.phase === 'transfer' && detail.unit === 'bytes' && detail.component === kind && detail.total === stream?.size && detail.state === 'running') {
                    this.observed[kind] = Math.max(this.observed[kind] || 0, Math.min(detail.count, stream.size));
                }
                if(detail.replacement)this.replacement={...this.replacement,...detail.replacement};
                if(detail.phase==='Replacing database tables atomically'&&detail.total>0&&detail.count>=detail.total)this.replacement.database={done:1,total:1};
                if(detail.phase==='Checking frontend health')for(const key of this.timeline.selected || [])this.replacement[key]={done:1,total:1};
                for (const event of detail.events || []) this.addEvent(event);
                if (detail.failure) this.addEvent({id:`failure:${detail.run}`,updated:detail.updated,phase:detail.failure.phase,component:detail.failure.component,error:detail.failure.error,state:'failed'});
                // A failed attempt may still be retrying inside PHP; it is not proof of rollback.
                if (detail.state === 'failed' && !this.resolvedRuns.has(detail.run) && this.frozen == null && !this.notice) this.notice = {kind:'warning',text:'Issue reported. Waiting for the operation result.',error:detail.failure?.error || ''};
                if (this.frozen==null && this.phase!=='recover' && detail.state!=='complete' && this.operationName!=='preflight') {
                    if (/Receiving and verifying|Verifying (?:staged|snapshot)|Staging (?:file|table)|Checking PHP compatibility|Checking destination/.test(detail.phase)) this.setPhase('verify');
                    else if (/Backing up destination file|Replacing file|Removing obsolete file|Preparing table replacement|Replacing database tables|Checking frontend health/.test(detail.phase)) this.setPhase('apply');
                }
            }
            if (this.receivedBytes() > previousBytes) this.lastMovement = this.clock();
            this.render(); this.save();
        }
        confirm(result) {
            if(!this.timeline||!Array.isArray(result?.pending))return;
            const streams=result.timeline?.streams || this.timeline.streams;
            for(const [kind,stream] of Object.entries(streams)){
                if(stream.size!=null&&stream.received===stream.size&&!result.pending.includes(kind))this.verified.add(kind);
            }
        }
        acceptDetail(detail) {
            if(detail.run&&this.staleRuns.has(detail.run))return false;
            const replacing=/Backing up destination file|Replacing file|Removing obsolete file|Preparing table replacement|Replacing database tables|Checking frontend health/;
            const verifying=/Receiving and verifying|Verifying (?:staged|snapshot)|Staging (?:file|table)|Checking PHP compatibility|Checking destination/;
            if(this.operationName==='preflight'&&(replacing.test(detail.phase)||verifying.test(detail.phase)))return false;
            if(this.operationName==='transfer'&&replacing.test(detail.phase))return false;
            return true;
        }
        addEvent(event) {
            if (!event.id || this.seen.has(event.id)) return;
            this.seen.add(event.id);
            if (this.seen.size > 500) this.seen = new Set(this.events.map(item => item.id));
            const routine = item => !item.error && !item.failure && !['retry','failed','resumed'].includes(item.state);
            if (routine(event)) {
                if(event.phase==='Checking frontend health')return;
                let component=String(event.component || '');
                if(event.state==='complete')component='';
                else if(/^(plugins|themes)\/[^/]+/.test(component))component=component.match(/^(plugins|themes)\/[^/]+/)[0];
                else if(component.startsWith('uploads/'))component=/^uploads\/\d{4}(?:\/|$)/.test(component)?component.slice(0,12):'uploads';
                else if(/media/i.test(event.phase)&&/^\d{4}(?:\/|$)/.test(component))component='uploads/'+component.slice(0,4);
                event={...event,component,phase:event.phase.replace(/\bfile$/,'files')};
                if(this.events.some(item=>routine(item)&&item.phase===event.phase&&item.component===component&&item.state===event.state))return;
            }
            this.events.push({...event});
            const keep = new Set([...this.events.filter(routine).slice(-64), ...this.events.filter(item => !routine(item)).slice(-16)]);
            this.events = this.events.filter(item => keep.has(item));
        }

        retry(error, wait = 0, id = `client-retry:${this.clock()}`, updated = this.clock()/1000) {
            if (!this.timeline) return;
            if (this.seen.has(id)) return;
            this.retryCount++; this.notice = {kind:'retry',text:'Retrying connection',error:String(error || ''),until:this.clock()+wait*1000};
            this.addEvent({id,updated,phase:'Retrying connection',error:String(error || ''),state:'retry'});
            this.render(); this.save();
        }
        resolved() {
            if (this.notice && ['retry','warning'].includes(this.notice.kind)) {
                this.addEvent({id:`resolved:${this.clock()}`,updated:this.clock()/1000,phase:'Connection restored',state:'resumed'});
                this.notice = null;
            }
            if (this.detail?.state === 'failed') this.resolvedRuns.add(this.detail.run);
            this.lastResolved = this.clock(); this.render();
        }
        ingestLogs(entries) {
            if (!this.timeline || this.frozen != null) return;
            const serverStart = this.timeline.started || (this.clock()/1000 - (this.timeline.elapsed || 0));
            for (const entry of entries || []) {
                const at = Date.parse(entry.time), context = entry.context || {};
                if (!Number.isFinite(at) || at < serverStart*1000 || (context.transfer_id && context.transfer_id !== this.timeline.id)) continue;
                if (entry.event !== 'Connection interrupted; retrying safe transfer step') continue;
                const id = `log:${entry.time}:${context.duration_ms}:${context.error}`;
                if (this.logs.has(id)) continue; this.logs.add(id);
                const serverNow = this.timeline.started ? (this.timeline.started + (this.timeline.elapsed || 0))*1000 : this.clock();
                const wait = Math.max(0, (context.duration_ms || 0)/1000 - Math.max(0,(serverNow-at)/1000));
                if (at < this.lastResolved - (this.clock()-serverNow)) {
                    this.retryCount++;
                    this.addEvent({id,updated:at/1000,phase:'Connection retry',error:context.error,state:'retry'});
                } else this.retry(context.error,wait,id,at/1000);
            }
            this.render(); this.save();
        }
        fail(error) {
            if (!this.timeline) return;
            this.notice = {kind:'error',text:'Transfer stopped',error:String(error?.message || error || '')};
            this.addEvent({id:`error:${this.clock()}`,updated:this.clock()/1000,phase:'Transfer stopped',error:this.notice.error,state:'failed'});
            this.render(); this.save();
        }
        save() {
            if (!this.timeline) return;
            try { sessionStorage.setItem('trickle-progress',JSON.stringify({id:this.timeline.id,events:this.events,retryCount:this.retryCount,logs:[...this.logs].slice(-200),replacement:this.replacement,verified:[...this.verified]})); } catch (_) { /* Optional. */ }
        }
        finish(status, result = {}) {
            if (!this.timeline) return;
            if(status==='applied')Object.keys(this.timeline.streams).forEach(kind=>this.verified.add(kind));
            this.phase = status === 'applied' ? 'complete' : status === 'restored' ? 'restored' : 'failed'; this.frozen = this.clock();
            if (this.phase !== 'failed') this.notice = null;
            this.addEvent({id:`terminal:${this.phase}`,updated:this.clock()/1000,phase:labels[this.phase],state:this.phase});
            this.render(); this.save();
        }
        receivedBytes() {
            return Object.keys(this.timeline?.streams || {}).reduce((total, kind) => total + this.streamReceived(kind), 0);
        }
        metrics() {
            if (!this.timeline) return null;
            return {elapsed:Math.max(0,((this.frozen ?? this.clock())-this.started)/1000), stalled:this.frozen == null && this.clock()-this.lastMovement>15000};
        }
        components() {
            if(!this.timeline)return [];
            const t=this.timeline, keys=t.selected || Object.keys(names).filter(key=>t.components[key]>0);
            return keys.map(key=>{
                const stream=t.streams[key==='database'?'database':'files'], amount=t.components[key];
                const received=this.verified.has(key==='database'?'database':'files')?1:stream?.size>0?this.streamReceived(key==='database'?'database':'files')/stream.size:0;
                const replacement=this.replacement[key];
                const replaced=['recover','restored'].includes(this.phase)?0:this.phase==='complete'?1:replacement?replacement.total>0?replacement.done/replacement.total:1:0;
                const state=this.phase==='complete'?'Applied':this.phase==='restored'?'Restored':this.phase==='recover'?'Recovering':this.phase==='failed'?'Stopped':replaced>=1?'Replaced':replaced>0?'Replacing':this.verified.has(key==='database'?'database':'files')?'Verified':received>=1?'Verifying':received>0?`${Math.floor(received*100)}% transferred`:this.phase==='preflight'?'Preparing':'Waiting';
                return {key,name:names[key],estimated:!!t.estimated?.[key],bytes:Number.isFinite(amount)?amount:null,received:Math.max(0,Math.min(1,received)),replaced:Math.max(0,Math.min(1,replaced)),state};
            });
        }
        status() {
            if(this.phase==='complete')return 'Destination updated.';
            if(this.phase==='restored')return 'Previous changes recovered.';
            if(this.phase==='failed')return 'Transfer stopped';
            if(this.phase==='recover')return 'Recovering destination…';
            if(this.notice?.kind==='retry')return 'Waiting to retry…';
            if(this.metrics()?.stalled)return 'Waiting for an update…';
            if(this.detail?.phase==='Checking frontend health')return 'Finishing up…';
            if(this.phase==='apply')return /Backing up/.test(this.detail?.phase || '')?'Saving backup…':'Replacing…';
            if(this.phase==='verify')return 'Verifying…';
            if(this.phase==='transfer')return 'Transferring…';
            return this.operationKind==='database'?'Preparing database…':this.operationKind==='files'?'Preparing files…':'Preparing…';
        }
        render() {
            if (!this.root || !this.timeline || (typeof document!=='undefined' && document.hidden)) return;
            const el=name=>this.root.querySelector(`[data-meter="${name}"]`), m=this.metrics();
            this.root.hidden=false;this.root.dataset.phase=this.phase;
            const elapsed=this.root.closest('dialog')?.querySelector('[data-transfer-elapsed]');
            if(elapsed){elapsed.hidden=false;elapsed.textContent=`${this.frozen==null?'Elapsed':'Total elapsed'} ${duration(m.elapsed)}`;}
            const status=this.status();if(el('phase').textContent!==status)el('phase').textContent=status;el('phase').hidden=['complete','restored'].includes(this.phase);
            el('retries').textContent=`${this.retryCount} ${this.retryCount===1?'retry':'retries'}`;el('retries').hidden=!this.retryCount;
            const notice=this.notice;
            el('notice').textContent=notice?`${notice.text}${notice.kind==='retry'&&notice.until>this.clock()?` in ${Math.ceil((notice.until-this.clock())/1000)}s`:''}${notice.error?` · ${notice.error}`:''}`:'';
            el('notice').dataset.tone=notice?.kind||'normal';el('notice').hidden=!notice;
            const components=this.components(), signature=JSON.stringify(components);
            if(signature!==this.signature){
                this.signature=signature;
                const structure=components.map(component=>component.key).join(':');
                if(structure!==this.componentStructure){el('components').replaceChildren();el('legend').replaceChildren();this.componentStructure=structure;}
                el('legend').style.setProperty('--component-count',String(components.length||1));
                const weighted=components.some(component=>component.bytes>0);
                for(const [index,component] of components.entries()){
                    let segment=el('components').children[index], item=el('legend').children[index];
                    if(!segment){
                        segment=document.createElement('div');segment.className='csm-progress-segment csm-progress-'+component.key;
                        segment.setAttribute('role','progressbar');segment.setAttribute('aria-label',component.name);segment.setAttribute('aria-valuemin','0');segment.setAttribute('aria-valuemax','100');
                        for(const part of ['received','replaced']){const fill=document.createElement('span');fill.className='csm-progress-'+part;segment.append(fill);}
                        el('components').append(segment);
                        item=document.createElement('li');item.className='csm-progress-'+component.key;
                        const label=document.createElement('strong');label.textContent=component.name;
                        item.append(label,document.createElement('span'),document.createElement('small'));el('legend').append(item);
                    }
                    const description=`${component.name}: ${component.bytes==null?'sizing':(component.estimated?'approximately ':'')+size(component.bytes)} · ${component.state}${component.key!=='database'?' · Transfer follows the shared file stream.':''}`;
                    segment.style.flexGrow=String(weighted?component.bytes||0:1);segment.title=description;segment.setAttribute('aria-valuenow',String(Math.round(component.received*100)));segment.setAttribute('aria-valuetext',description);
                    segment.children[0].style.width=`${component.received*100}%`;segment.children[1].style.width=`${component.replaced*100}%`;
                    item.children[1].textContent=component.bytes==null?'Sizing…':(component.estimated?'~':'')+size(component.bytes);item.children[2].textContent=component.state;
                }
            }
            const latest=[...this.events].reverse(), eventSignature=latest.map(e=>e.id).join(':');
            if(eventSignature!==this.eventSignature){
                this.eventSignature=eventSignature;el('events').replaceChildren();
                for(const event of latest){
                    const item=document.createElement('li'),time=document.createElement('time'),problem=event.error||event.failure;
                    const subject=document.createElement(problem?'code':'strong'),detail=document.createElement('span');subject.className='csm-event-subject';detail.className='csm-event-component';
                    const date=new Date(event.updated*1000);time.dateTime=date.toISOString();time.textContent=date.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});
                    const path=event.component||'';
                    const type=path.startsWith('plugins/')?'Plugin':path.startsWith('themes/')?'Theme':path.startsWith('uploads')?'Media':/table/i.test(event.phase)&&path?'Table':'';
                    const name=type==='Plugin'?path.slice(8):type==='Theme'?path.slice(7):type==='Media'?(path==='uploads'?'Other files':path.slice(8)):path;
                    subject.textContent=problem?path||event.phase:name||event.phase;
                    detail.textContent=problem?[event.phase,event.error||event.failure?.error].filter(Boolean).join(' · '):name?[type,event.phase].filter(Boolean).join(' · '):'';
                    item.dataset.state=event.state||'normal';item.append(time,subject,detail);
                    if(event.error||event.failure?.error){const copy=document.createElement('button');copy.type='button';copy.className='button-link';copy.dataset.copyEvent=event.id;copy.textContent='Copy error details';item.append(copy);}
                    el('events').append(item);
                }
            }
            el('events-empty').hidden=latest.length>0;

        }
    }
    globalThis.CSMTransferProgress=TransferProgress;
    if(typeof module!=='undefined'&&module.exports)module.exports={TransferProgress,duration,size};
})();
