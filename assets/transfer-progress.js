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
            this.configure({direction:'pull',transport:'stream'});
            root?.addEventListener('click', event => {
                const tab=event.target.closest('[data-stage-tab]');
                if(tab&&!tab.disabled)this.selectStage(tab.dataset.stageTab);
                if(event.target.closest('[data-meter="follow"]')){if(this.following){this.following=false;this.render();}else this.followLive();}
                const copy=event.target.closest('[data-copy-event]');
                if(copy)this.copyEvent(copy.dataset.copyEvent,copy);
            });
            root?.addEventListener('keydown', event => {
                if(!event.target.matches('[data-stage-tab]')||!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
                const tabs=[...root.querySelectorAll('[data-stage-tab]:not(:disabled)')], i=tabs.indexOf(event.target);
                const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(i+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
                event.preventDefault();this.selectStage(tabs[next].dataset.stageTab);tabs[next].focus();
            });
        }
        async copyEvent(id, button) {
            const item=this.events.find(item=>item.id===id);if(!item)return;
            try { await navigator.clipboard.writeText([item.phase,item.component,item.error||item.failure?.error].filter(Boolean).join(' · '));button.textContent='Copied'; }
            catch (_) { button.textContent='Select error text to copy'; }
        }
        configure(route) { this.direction = route.direction || 'pull'; this.transport = route.transport || 'stream'; }
        begin(timeline) {
            if (this.timeline?.id === timeline.id) return;
            this.chart?.destroy(); this.chart = null;
            this.timeline = timeline; this.started = this.clock() - Math.max(0, timeline.elapsed || 0) * 1000;
            this.events = []; this.seen = new Set(); this.logs = new Set(); this.samples = []; this.observed = {}; this.verified = new Set();
            this.rate = null; this.last = null; this.rateKind = null; this.phaseRate = null; this.phaseLast = null; this.phaseKey = '';
            this.phase = 'preflight'; this.stoppedPhase = null; this.frozen = null; this.detail = null; this.lastMovement = this.clock(); this.retryCount = 0; this.notice = null; this.lastResolved = 0;
            this.operationName=null;this.staleRuns=new Set();this.stages={};this.stageDurations={};this.timedStage=null;this.stageStarted=null;this.terminalHealth=null;this.liveStage=null;this.selectedStage=null;this.following=true;
            this.signature = ''; this.eventSignature = null; this.operationKind = null;
            this.resolvedRuns = new Set(); this.restore(); this.render();
        }
        restore() {
            try {
                const saved = JSON.parse(sessionStorage.getItem('trickle-progress') || 'null');
                if (saved?.id === this.timeline.id) {
                    this.events = saved.events.slice(-16); this.seen = new Set(this.events.map(event => event.id));
                    this.stages = saved.stages || {}; this.stageDurations = saved.stageDurations || {};
                    if(this.stageDurations.verify!=null)this.stageDurations.transfer=(this.stageDurations.transfer||0)+this.stageDurations.verify;delete this.stageDurations.verify;delete this.stages.verify;
                    this.verified=new Set(saved.verified || []); this.retryCount = saved.retryCount || 0; this.logs = new Set(saved.logs || []); this.samples = (saved.samples || []).slice(-120);
                }
            } catch (_) { /* Storage is optional. */ }
        }
        operation(op, kind) {
            kind=kind || null;
            if(op!==this.operationName||kind!==this.operationKind){if(this.detail?.run)this.staleRuns.add(this.detail.run);this.detail=null;}
            this.operationName=op;
            this.operationKind = kind || null;
            this.setPhase(op === 'commit' ? 'verify' : op === 'rollback' ? 'recover' : op === 'preflight' ? 'preflight' : 'transfer');
            if (kind && this.rateKind !== kind) this.resetRate(kind);
        }
        resetRate(kind) {
            this.rateKind = kind; this.rate = null;
            this.last = {value:this.streamReceived(kind),time:this.clock()}; this.lastMovement = this.clock();
        }
        setPhase(phase) {
            if (this.phase === phase || this.frozen != null) return;
            this.phase = phase; this.phaseRate = null; this.phaseLast = null; this.phaseKey = ''; this.lastMovement = this.clock();
            if (phase === 'transfer') this.resetRate(this.activeKind());
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
                if (this.phase !== 'transfer' && movement !== this.movement) this.lastMovement = this.clock();
                this.movement = movement;
                const kind = this.activeKind(), stream = this.timeline?.streams[kind];
                if (this.phase === 'transfer' && detail.unit === 'bytes' && detail.component === kind && detail.total === stream?.size && detail.state === 'running') {
                    this.observed[kind] = Math.max(this.observed[kind] || 0, Math.min(detail.count, stream.size));
                }
                if (detail.total > 0 && detail.unit !== 'bytes' && ['preflight','verify','apply','recover'].includes(this.phase)) {
                    const key = `${detail.phase}:${detail.total}:${detail.unit}`;
                    if (this.phaseKey !== key) { this.phaseKey = key; this.phaseLast = null; this.phaseRate = null; }
                    this.sample(detail.count, 'phaseLast', 'phaseRate');
                } else { this.phaseLast = null; this.phaseRate = null; }
                for (const event of detail.events || []) this.addEvent(event);
                if (detail.failure) this.addEvent({id:`failure:${detail.run}`,updated:detail.updated,phase:detail.failure.phase,component:detail.failure.component,error:detail.failure.error,state:'failed'});
                // A failed attempt may still be retrying inside PHP; it is not proof of rollback.
                if (detail.state === 'failed' && !this.resolvedRuns.has(detail.run) && this.frozen == null && !this.notice) this.notice = {kind:'warning',text:'Issue reported. Waiting for the operation result.',error:detail.failure?.error || ''};
                if (this.frozen==null && this.phase!=='recover' && detail.state!=='complete' && this.operationName!=='preflight') {
                    if (/Receiving and verifying|Verifying staged|Checking PHP compatibility|Checking destination/.test(detail.phase)) this.setPhase('verify');
                    else if (/Backing up destination file|Replacing file|Removing obsolete file|Preparing table replacement|Replacing database tables|Checking frontend health/.test(detail.phase)) this.setPhase('apply');
                }
            }
            if (this.timeline && this.phase === 'transfer') {
                const kind = this.activeKind(); if (kind !== this.rateKind) this.resetRate(kind);
                this.sample(this.streamReceived(kind), 'last', 'rate'); this.recordSample();
            }
            this.render(); this.save();
        }
        confirm(result) {
            if(!this.timeline||!Array.isArray(result?.pending))return;
            const streams=result.timeline?.streams || this.timeline.streams;
            for(const [kind,stream] of Object.entries(streams)){
                if(stream.size!=null&&stream.received===stream.size&&!result.pending.includes(kind))this.verified.add(kind);
            }
        }
        byteRanges() {
            const windows=this.windows();
            if(windows)return windows.items.map(range=>({...range,verified:this.verified.has(range.kind)}));
            let offset=0;
            return Object.entries(this.timeline.streams).map(([kind,stream])=>{
                const range={kind,startBytes:offset,endBytes:offset+(stream.size||0),receivedEnd:offset+this.streamReceived(kind),verified:this.verified.has(kind),state:this.streamReceived(kind)===(stream.size||0)?'received':this.streamReceived(kind)>0?'active':'pending'};
                offset=range.endBytes;return range;
            });
        }
        acceptDetail(detail) {
            if(detail.run&&this.staleRuns.has(detail.run))return false;
            const replacing=/Backing up destination file|Replacing file|Removing obsolete file|Preparing table replacement|Replacing database tables|Checking frontend health/;
            const verifying=/Receiving and verifying|Verifying staged|Checking PHP compatibility|Checking destination/;
            if(this.operationName==='preflight'&&(replacing.test(detail.phase)||verifying.test(detail.phase)))return false;
            if(this.operationName==='transfer'&&replacing.test(detail.phase))return false;
            return true;
        }
        sample(value, lastKey, rateKey) {
            const now = this.clock(), last = this[lastKey];
            if (!last || value < last.value) { this[lastKey] = {value,time:now}; this[rateKey] = null; return; }
            if (value > last.value && now > last.time) {
                const rate = (value - last.value) / ((now - last.time) / 1000);
                this[rateKey] = this[rateKey] == null ? rate : this[rateKey] * .65 + rate * .35;
                this[lastKey] = {value,time:now}; this.lastMovement = now;
            }
        }
        recordSample() {
            if (this.rate == null) return;
            const now = this.clock(), last = this.samples.at(-1);
            if (last && now - last.at < 1000) return;
            this.samples.push({at:now,x:Math.max(0,(now-this.started)/1000),y:now-this.lastMovement > 15000 ? 0 : this.rate/1e6,bytes:this.streamReceived(this.activeKind()),position:this.metrics().received,kind:this.activeKind()});
            this.samples = this.samples.slice(-120);
        }
        addEvent(event) {
            if (!event.id || this.seen.has(event.id)) return;
            this.seen.add(event.id);
            if (this.seen.size > 500) this.seen = new Set(this.events.map(item => item.id));
            const last = this.events.at(-1);
            if (last && last.phase === event.phase && last.component === event.component && last.state === event.state && !event.error && !event.failure) return;
            const routine = item => !item.error && !item.failure && !['retry','failed','resumed'].includes(item.state);
            if (last && routine(last) && routine(event) && last.phase === event.phase && last.state === event.state && last.component && event.component) {
                this.events[this.events.length - 1] = {...event, grouped:(last.grouped || 1) + 1};
            } else this.events.push({...event});
            const keep = new Set([...this.events.filter(routine).slice(-8), ...this.events.filter(item => !routine(item)).slice(-8)]);
            this.events = this.events.filter(item => keep.has(item));
        }
        retry(error, wait = 0, id = `client-retry:${this.clock()}`, updated = this.clock()/1000) {
            if (!this.timeline) return;
            if (this.seen.has(id)) return;
            this.retryCount++; this.notice = {kind:'retry',text:'Retrying connection',error:String(error || ''),until:this.clock()+wait*1000};
            this.addEvent({id,updated,phase:'Retrying connection',error:String(error || ''),state:'retry'});
            this.rate = null; this.last = null; this.render(); this.save();
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
            try { sessionStorage.setItem('trickle-progress',JSON.stringify({id:this.timeline.id,events:this.events,retryCount:this.retryCount,logs:[...this.logs].slice(-200),samples:this.samples,stages:this.stages,stageDurations:this.stageTimes(),verified:[...this.verified]})); } catch (_) { /* Optional. */ }
        }
        finish(status, result = {}) {
            if (!this.timeline) return;
            this.terminalHealth=result.health || null;
            if(status==='applied')Object.keys(this.timeline.streams).forEach(kind=>this.verified.add(kind));
            if(status==='applied'&&this.stages.transfer){const m=this.metrics();Object.assign(this.stages.transfer,{received:m.received,total:m.total,percent:100,finished:true});}
            this.stoppedPhase = this.phase;
            this.phase = status === 'applied' ? 'complete' : status === 'restored' ? 'restored' : 'failed'; this.frozen = this.clock();
            if (this.phase !== 'failed') this.notice = null;
            this.addEvent({id:`terminal:${this.phase}`,updated:this.clock()/1000,phase:labels[this.phase],state:this.phase});
            this.render(); this.save();
        }
        metrics() {
            if (!this.timeline) return null;
            const t = this.timeline, now = this.frozen ?? this.clock(), streams = Object.entries(t.streams || {});
            const total = streams.reduce((n,[,s])=>n+(s.size||0),0), received = streams.reduce((n,[kind])=>n+this.streamReceived(kind),0);
            const stalled = this.frozen == null && this.clock()-this.lastMovement>15000;
            const stream = t.streams[this.activeKind()]; let eta = null;
            if (!stalled && !this.notice && this.phase === 'transfer' && t.ready && this.rate > 0 && this.clock()-this.started>=3000 && this.streamReceived(this.activeKind())<(stream?.size||0)) eta = (stream.size-this.streamReceived(this.activeKind()))/this.rate;
            if (!stalled && !this.notice && ['preflight','verify','apply','recover'].includes(this.phase) && this.phaseRate>0 && this.detail?.total>this.detail?.count) eta = (this.detail.total-this.detail.count)/this.phaseRate;
            return {elapsed:Math.max(0,(now-this.started)/1000),percent:this.phase==='complete'?100:t.ready?(total?received/total*100:0):null,received,total,eta,stalled,speed:this.frozen!=null||this.phase!=='transfer'||this.notice?null:stalled?0:this.rate};
        }
        windows() {
            if(this.direction!=='push'&&this.transport!=='chunks')return null;
            const streams=Object.entries(this.timeline?.streams || {}).filter(([,s])=>s.size>0);
            const unit=this.transport==='chunks'?524288:16777216, count=streams.reduce((n,[,s])=>n+Math.ceil(s.size/unit),0);
            if(!count)return null;
            const group=Math.max(1,Math.ceil(count/120)), items=[];let offset=0, sequence=0, complete=0;
            for(const [kind,stream] of streams){
                const n=Math.ceil(stream.size/unit), received=this.streamReceived(kind);
                complete+=received===stream.size?n:Math.floor(received/unit);
                for(let first=0;first<n;first+=group){
                    const end=Math.min(n,first+group), startBytes=offset+first*unit, endBytes=offset+Math.min(stream.size,end*unit);
                    items.push({kind,first:sequence+first+1,last:sequence+end,startBytes,endBytes,receivedEnd:Math.min(endBytes,Math.max(startBytes,offset+received)),state:offset+received>=endBytes?'received':offset+received>=startBytes?'active':'pending'});
                }
                offset+=stream.size;sequence+=n;
            }
            return {unit,count,complete,received:this.metrics().received,items,group};
        }
        stageTimes() {
            const times={...this.stageDurations};
            if(this.timedStage)times[this.timedStage]=(times[this.timedStage]||0)+Math.max(0,(this.frozen??this.clock())-this.stageStarted)/1000;
            return times;
        }
        trackStageTime(stage, terminal) {
            if(this.timedStage===stage&&!terminal)return;
            if(this.timedStage)this.stageDurations=this.stageTimes();
            this.timedStage=terminal?null:stage;
            if(!terminal){this.stageDurations[stage]??=0;this.stageStarted=this.clock();}
        }
        currentStage() {
            const phase=this.phase==='failed'?this.stoppedPhase:this.phase;
            if(phase==='verify')return 'transfer';
            if(phase==='complete')return 'health';
            if(['recover','restored'].includes(phase))return 'apply';
            if(phase==='apply'&&/health|Transfer complete/i.test(this.detail?.phase||''))return 'health';
            return phase || 'preflight';
        }
        selectStage(stage) {
            if(stage==='verify')stage='transfer';
            if(!this.stages[stage] && this.phase!=='complete')return;
            this.selectedStage=stage;this.following=stage===this.currentStage();this.render();
        }
        followLive() { this.following=true;this.selectedStage=this.currentStage();this.render(); }
        presentation() {
            if(!this.timeline)return null;
            const stage=this.currentStage(), m=this.metrics(), terminal=this.frozen!=null;
            this.trackStageTime(stage,terminal);
            if(stage!==this.liveStage){
                if(this.stages[this.liveStage])this.stages[this.liveStage].finished=true;
                this.liveStage=stage;if(this.following!==false)this.selectedStage=stage;
            }
            const detail=this.detail || {}, counted=detail.total>0&&detail.unit!=='bytes'&&stage!=='transfer';
            const percent=counted?Math.min(100,Math.max(0,(detail.count||0)/detail.total*100)):stage==='transfer'?m.percent:null;
            const label=stage==='preflight'?(this.operationKind==='files'?'Packaging files':this.operationKind==='database'?'Packaging database':'Preparing streams'):{transfer:'Transfer and verification',verify:'Verifying staged data',apply:this.phase==='recover'?'Recovering destination':'Replacing destination data',health:'Checking destination health'}[stage];
            this.stages[stage]={label,detail:{component:detail.component,phase:detail.phase,count:detail.count,total:detail.total,unit:detail.unit},percent,verifying:this.phase==='verify',received:stage==='transfer'?m.received:detail.count||0,total:stage==='transfer'?m.total:detail.total||0,speed:m.speed,eta:m.eta,finished:terminal};
            const selected=this.selectedStage || stage;
            const view=this.stages[selected] || {label:{preflight:'Preparation',transfer:'Transfer',verify:'Verification',apply:'Replacement',health:'Health check'}[selected],finished:terminal,detail:{},unreported:true};
            return {...view,stage:selected,live:selected===stage,terminal,activeStage:stage};
        }
        render() {
            const view=this.presentation();
            if (!this.root || !view || (typeof document!=='undefined' && document.hidden)) return;
            const el=name=>this.root.querySelector(`[data-meter="${name}"]`), set=(name,text)=>{if(el(name))el(name).textContent=text;}, m=this.metrics(), t=this.timeline;
            this.root.hidden=false; this.root.dataset.phase=this.phase;this.root.dataset.stage=view.stage;
            const detail=view.detail || {};
            const phase=view.label;
            set('phase',this.phase==='complete'?`${this.direction==='push'?'Push':'Pull'} complete`:this.phase==='restored'?'Destination restored':this.phase==='failed'?'Transfer stopped':this.direction==='push'?'Pushing…':'Pulling…');
            set('elapsed',`Elapsed ${duration(m.elapsed)}`);
            const health=this.terminalHealth;
            const resultText=view.stage==='health'&&this.phase==='complete'&&health?(health.verified?`Homepage check passed${health.status?' · HTTP '+health.status:''}`:`Homepage check inconclusive${health.status?' · HTTP '+health.status:''}`):view.unreported?'No detailed progress was reported for this stage.':'';
            set('stage-result',resultText);el('stage-result').hidden=!resultText;
            const showSpeed=view.stage==='transfer'&&view.live&&!view.finished&&view.speed!=null;
            const showEta=view.live&&!view.finished&&view.eta!=null;
            el('speed-stat').hidden=!showSpeed;el('eta-stat').hidden=!showEta;el('stats').hidden=!showSpeed&&!showEta;
            set('speed',showSpeed?`${(view.speed/1e6).toFixed(1)} MB/s`:'');set('eta',showEta?`~${duration(Math.max(1,view.eta))}`:'');
            set('eta-label',view.verifying?'Verification remaining':view.stage==='transfer'?'Stream remaining':'Step remaining');
            set('badge',this.notice?.kind==='error'?'Stopped':this.notice?.kind==='retry'?'Retrying':this.frozen!=null?labels[this.phase]:m.stalled?'Awaiting update':'In progress');
            el('badge').hidden=this.frozen!=null;
            el('badge').dataset.tone=this.notice?.kind || 'normal';
            set('retries',`${this.retryCount} ${this.retryCount===1?'retry':'retries'}`);el('retries').hidden=!this.retryCount;
            const notice=this.notice;
            set('notice',notice?`${notice.text}${notice.kind==='retry'&&notice.until>this.clock()?` in ${Math.ceil((notice.until-this.clock())/1000)}s`:''}${notice.error?` · ${notice.error}`:''}`:'');
            el('notice').dataset.tone=notice?.kind||'normal';el('notice').hidden=!notice;
            set('follow',this.following?'Following live':'Follow live');
            el('follow').setAttribute('aria-pressed',String(this.following));el('follow').hidden=this.frozen!=null;
            const times=this.stageTimes();
            this.root.querySelectorAll('[data-stage-tab]').forEach(node=>{
                const key=node.dataset.stageTab, selected=key===view.stage;
                const timer=node.querySelector('[data-stage-time]');timer.hidden=times[key]==null;timer.textContent=times[key]==null?'':duration(times[key]);
                node.disabled=!this.stages[key]&&this.phase!=='complete';node.tabIndex=selected?0:-1;
                node.setAttribute('aria-selected',String(selected));node.dataset.state=this.phase==='failed'&&key===view.activeStage?'failed':this.phase==='complete'||this.stages[key]?.finished?'done':key===view.activeStage?'current':'pending';
                if(key===view.activeStage&&this.frozen==null)node.setAttribute('aria-current','step');else node.removeAttribute('aria-current');
            });
            this.root.querySelectorAll('[data-stage-panel]').forEach(node=>{
                node.hidden=node.dataset.stagePanel!==view.stage;
                if(!node.hidden&&el('stage-content').parentElement!==node)node.prepend(el('stage-content'));
            });
            const keys=t.selected||Object.keys(names).filter(k=>t.components[k]>0), payload=keys.reduce((n,k)=>n+(t.components[k]||0),0), signature=JSON.stringify([keys,t.components,t.ready]);
            if(signature!==this.signature){this.signature=signature;el('segments').replaceChildren();el('legend').replaceChildren();for(const key of keys){
                const known=t.streams[key==='database'?'database':'files']?.size!=null, segment=document.createElement('span');segment.className=`csm-meter-${key}`;segment.style.flexGrow=String(payload?t.components[key]||0:1);segment.style.flexBasis='0';segment.title=`${names[key]}: ${known?size(t.components[key]||0):'measuring'}`;el('segments').append(segment);
                const item=document.createElement('li'),dot=document.createElement('i'),label=document.createElement('span'),value=document.createElement('strong');dot.className=`csm-meter-${key}`;dot.setAttribute('aria-hidden','true');label.textContent=names[key];value.textContent=known?size(t.components[key]||0):'Sizing…';item.append(dot,label,value);el('legend').append(item);
            }}
            const isTransfer=view.stage==='transfer', windows=isTransfer?this.windows():null;
            const total=view.stage==='health'&&view.finished?1:view.total, done=view.stage==='health'&&view.finished?1:view.received;
            const unit=isTransfer?'bytes':view.stage==='health'?'checks':detail.unit||'items';
            const fraction=total>0?Math.max(0,Math.min(100,done/total*100)):null;
            const format=n=>unit==='bytes'?size(n):Number(n||0).toLocaleString();
            el('windows-wrap').hidden=!windows;
            if(windows){set('window-label',windows.unit===16777216?'16 MiB windows':'512 KiB chunks');set('window-count',`${windows.complete} / ${windows.count} received`);}
            set('transfer-received',total?`${format(done)} of ${format(total)} ${unit==='bytes'?'received':unit}`:done?`${format(done)} ${unit} processed`:'Working…');
            set('transfer-percent',fraction==null?'':`${Math.floor(fraction)}%`);
            const progress=el('transfer-progress');progress.setAttribute('aria-label',`${phase} progress`);
            if(fraction==null)progress.removeAttribute('aria-valuenow');else progress.setAttribute('aria-valuenow',String(Math.round(fraction)));
            progress.setAttribute('aria-valuetext',total?`${format(done)} of ${format(total)} ${unit}`:'Processing; total not yet reported');
            el('speed-wrap').hidden=false;el('plot').hidden=!total;el('indeterminate').hidden=!!total;
            el('speed-wrap').dataset.mode=isTransfer?'transfer':'processing';
            el('chart').setAttribute('aria-label',isTransfer?'Speed over received bytes. Shaded ranges show received and pending bytes. Use arrow keys to inspect samples.':`${phase}. Shading shows processed ${unit}; hover to inspect.`);
            el('byte-legend').hidden=!isTransfer;
            const verifiedBytes=Object.entries(t.streams).reduce((n,[kind,stream])=>n+(this.verified.has(kind)?stream.size||0:0),0);
            set('verified-size',`${size(verifiedBytes)} verified`);
            const verifying=isTransfer&&view.verifying&&!view.finished, verificationTotal=detail.total>0&&detail.unit!=='bytes'?detail.total:null;
            const verificationPercent=verificationTotal?Math.min(100,(detail.count||0)/verificationTotal*100):null;
            el('verification').hidden=!verifying;
            set('verification-count',`Verifying${Number.isFinite(detail.count)&&detail.unit!=='bytes'?' · '+Number(detail.count).toLocaleString()+(verificationTotal?' of '+Number(verificationTotal).toLocaleString():'')+' '+(detail.unit||'items'):''}`);
            set('verification-percent',verificationPercent==null?'':`${Math.floor(verificationPercent)}%`);
            el('verification-progress').hidden=verificationPercent==null;el('verification-busy').hidden=verificationPercent!=null;
            if(verificationPercent==null)el('verification-progress').removeAttribute('aria-valuenow');else el('verification-progress').setAttribute('aria-valuenow',String(Math.round(verificationPercent)));
            el('verification-fill').style.transform=`scaleX(${(verificationPercent||0)/100})`;
            const latest=[...this.events].reverse(), eventSignature=latest.map(e=>e.id+':'+(e.grouped||1)).join(':');
            if(eventSignature!==this.eventSignature){
                this.eventSignature=eventSignature;el('events').replaceChildren();
                for(const event of latest){
                    const item=document.createElement('li'),time=document.createElement('time'),text=document.createElement('span'),component=document.createElement('code');
                    const date=new Date(event.updated*1000);time.dateTime=date.toISOString();time.textContent=date.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});
                    text.textContent=[event.phase,event.grouped>1?`${event.grouped} items`:null,event.error||event.failure?.error].filter(Boolean).join(' · ');
                    component.textContent=event.component||'';item.dataset.state=event.state||'normal';item.append(time,text,component);
                    if(event.error||event.failure?.error){const copy=document.createElement('button');copy.type='button';copy.className='button-link';copy.dataset.copyEvent=event.id;copy.textContent='Copy error details';item.append(copy);}
                    el('events').append(item);
                }
            }
            el('events-empty').hidden=latest.length>0;
            if(this.phase==='transfer')this.recordSample();
            if(total&&!this.chart&&typeof globalThis.CSMProgressChart==='function')this.chart=new globalThis.CSMProgressChart(el('chart'),el('chart-reading'));
            const style=getComputedStyle(el('stage-content'));
            if(total)this.chart?.update(isTransfer?this.samples:[],this.phase,{stage:view.stage,unit,total,received:done,color:style.getPropertyValue('--stage-color').trim(),wash:style.getPropertyValue('--stage-wash').trim(),windows:isTransfer?this.byteRanges():[],offsets:Object.fromEntries(Object.entries(t.streams).map(([kind],i,a)=>[kind,a.slice(0,i).reduce((n,[,s])=>n+(s.size||0),0)]))});
            else {this.chart?.chart?.stop();set('chart-reading','Total not yet reported.');}

        }
    }
    globalThis.CSMTransferProgress=TransferProgress;
    if(typeof module!=='undefined'&&module.exports)module.exports={TransferProgress,duration,size};
})();
