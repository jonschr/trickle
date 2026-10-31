/* Shared by the dashboard and its progress checks; all times use the observer's clock. */
(() => {
    'use strict';
    const names = {database:'Database', plugins:'Plugins', themes:'Themes', media:'Media'};
    const size = n => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MB` : `${(n / 1073741824).toFixed(1)} GB`;
    const duration = seconds => {
        seconds = Math.max(0, Math.round(seconds));
        const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
        return h ? `${h}h ${m}m` : m ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
    };
    class TransferProgress {
        constructor(root, clock = () => Date.now()) {
            this.root = root; this.clock = clock; this.events = []; this.phase = 'preflight'; this.frozen = null;
        }
        begin(timeline) {
            if (this.timeline?.id === timeline.id) return;
            this.timeline = timeline; this.started = this.clock() - Math.max(0, timeline.elapsed || 0) * 1000;
            this.events = []; this.seen = new Set(); this.rate = null; this.last = null; this.phaseRate = null; this.phaseLast = null;
            this.phase = 'preflight'; this.frozen = null; this.detail = null; this.lastMovement = this.clock();
            this.last = {value:Object.values(timeline.streams || {}).reduce((sum, stream) => sum + (stream.received || 0), 0), time:this.clock()};
            this.restore(); this.render();
        }
        restore() {
            try {
                const saved = JSON.parse(sessionStorage.getItem('trickle-progress') || 'null');
                if (saved?.id === this.timeline.id) {
                    this.events = saved.events.slice(-12); this.seen = new Set(this.events.map(event => event.id));
                }
            } catch (_) { /* Storage is optional, including in private browsing. */ }
        }
        setPhase(phase) {
            if (this.phase === phase || this.frozen != null) return;
            this.phase = phase; this.phaseRate = null; this.phaseLast = null; this.lastMovement = this.clock();
            if (phase === 'transfer' && this.timeline) this.last = {value:Object.values(this.timeline.streams || {}).reduce((sum, stream) => sum + (stream.received || 0), 0),time:this.clock()};
            this.render();
        }
        update(result, phase) {
            const timeline = result?.timeline || result?.progress?.timeline;
            if (timeline) {
                this.begin(timeline); this.timeline = timeline;
                const received = Object.values(timeline.streams).reduce((sum, stream) => sum + Math.min(stream.received, stream.size || 0), 0);
                this.sample(received, 'last', 'rate');
            }
            if (phase) this.setPhase(phase);
            const detail = result?.progress?.phase ? result.progress : result?.phase ? result : null;
            if (detail) {
                this.detail = detail;
                if (['apply', 'recover'].includes(this.phase) && detail.total > 0 && detail.unit !== 'bytes') {
                    const key = `${detail.phase}:${detail.total}:${detail.unit}`;
                    if (this.phaseKey !== key) { this.phaseKey = key; this.phaseLast = null; this.phaseRate = null; }
                    this.sample(detail.count, 'phaseLast', 'phaseRate');
                }
                for (const event of detail.events || []) this.addEvent(event);
                if (detail.failure) this.addEvent({id:`failure:${detail.run}`,updated:detail.updated,phase:detail.failure.phase,component:detail.failure.component,error:detail.failure.error,state:'failed'});
                if (this.phase === 'transfer' && /Receiving and verifying|Verifying staged/.test(detail.phase)) this.setPhase('verify');
                if (detail.state === 'failed') this.setPhase('recover');
            }
            this.render(); this.save();
        }
        sample(value, lastKey, rateKey) {
            const now = this.clock(), last = this[lastKey];
            if (!last) { this[lastKey] = {value, time:now}; return; }
            if (value < last.value) { this[lastKey] = {value, time:now}; this[rateKey] = null; return; }
            if (value > last.value && now > last.time) {
                const rate = (value - last.value) / ((now - last.time) / 1000);
                this[rateKey] = this[rateKey] == null ? rate : this[rateKey] * .65 + rate * .35;
                this[lastKey] = {value,time:now}; this.lastMovement = now;
            }
        }
        addEvent(event) {
            if (!event.id || this.seen.has(event.id)) return;
            this.seen.add(event.id);
            if (this.seen.size > 500) this.seen = new Set(this.events.map(item => item.id));
            if (/stream$/.test(event.phase) && this.events.some(item => item.phase === event.phase && item.component === event.component)) return;
            const last = this.events.at(-1);
            if (last && last.phase === event.phase && last.component === event.component && last.state === event.state && !event.error && !event.failure) return;
            this.events.push(event); this.events = this.events.slice(-12);
        }
        save() {
            if (!this.timeline) return;
            try { sessionStorage.setItem('trickle-progress', JSON.stringify({id:this.timeline.id,events:this.events})); } catch (_) { /* Optional. */ }
        }
        finish(status) {
            if (!this.timeline) return;
            this.phase = status === 'applied' ? 'complete' : status === 'restored' ? 'restored' : 'failed';
            this.frozen = this.clock();
            const label = {complete:'Transfer complete',restored:'Destination restored',failed:'Transfer stopped'}[this.phase];
            if (this.events.at(-1)?.phase !== label) this.addEvent({id:`terminal:${this.phase}`,updated:this.clock() / 1000,phase:label,component:'',state:this.phase});
            this.render(); this.save();
        }
        metrics() {
            const timeline = this.timeline, now = this.frozen ?? this.clock();
            if (!timeline) return null;
            const streams = Object.entries(timeline.streams || {}), total = streams.reduce((sum, [,stream]) => sum + (stream.size || 0), 0);
            const received = streams.reduce((sum, [,stream]) => sum + Math.min(stream.size || 0, stream.received || 0), 0);
            const payload = Object.values(timeline.components || {}).reduce((sum, value) => sum + value, 0);
            let completed = 0;
            for (const [kind, stream] of streams) {
                const weight = kind === 'database' ? timeline.components.database || 0 : payload - (timeline.components.database || 0);
                completed += weight * (stream.size ? Math.min(1, stream.received / stream.size) : 0);
            }
            const percent = this.phase === 'complete' ? 100 : timeline.ready && total ? (payload ? completed / payload : received / total) * 100 : null;
            const stalled = !this.frozen && this.clock() - this.lastMovement > 15000;
            let eta = null;
            if (!stalled && this.phase === 'transfer' && timeline.ready && this.rate > 0 && this.last && this.clock() - this.started >= 3000 && received < total) eta = (total - received) / this.rate;
            if (!stalled && this.phase === 'apply' && this.phaseRate > 0 && this.detail?.total > this.detail?.count) eta = (this.detail.total - this.detail.count) / this.phaseRate;
            return {elapsed:Math.max(0, (now - this.started) / 1000), percent, received, total, eta, stalled};
        }
        render() {
            if (!this.root || !this.timeline) return;
            const el = name => this.root.querySelector(`[data-meter="${name}"]`), m = this.metrics(), timeline = this.timeline;
            this.root.hidden = false;
            const phase = {preflight:'Preparing streams',transfer:'Transferring',verify:'Verifying staged data',apply:'Applying changes',recover:'Recovering',complete:'Complete',restored:'Destination restored',failed:'Stopped'}[this.phase];
            el('phase').textContent = phase;
            el('percent').textContent = this.phase === 'complete' ? '100%' : ['preflight','apply','recover','restored','failed','verify'].includes(this.phase) ? '' : m.percent == null ? '' : `~${Math.min(99, Math.floor(m.percent))}% staged`;
            el('elapsed').textContent = duration(m.elapsed);
            el('eta').textContent = this.frozen != null ? '—' : m.eta != null ? `~${duration(Math.max(1,m.eta))}` : m.stalled ? 'Waiting…' : this.phase === 'preflight' ? 'Sizing…' : this.phase === 'transfer' ? 'Estimating…' : 'Finishing…';
            el('eta-label').textContent = this.phase === 'apply' && m.eta != null ? 'This step remaining' : 'Transfer remaining';
            el('received').textContent = timeline.ready ? `${size(m.received)} / ${size(m.total)} compressed` : 'Measuring selected payloads…';
            const keys = timeline.selected || Object.keys(names).filter(key => timeline.components[key] > 0), payload = keys.reduce((sum,key) => sum + (timeline.components[key] || 0), 0);
            const signature = JSON.stringify([keys,timeline.components,Object.values(timeline.streams).map(stream => stream.size)]);
            if (signature !== this.signature) {
                this.signature = signature; el('segments').replaceChildren(); el('legend').replaceChildren();
                for (const key of keys) {
                    const known = timeline.streams[key === 'database' ? 'database' : 'files']?.size != null;
                    const segment = document.createElement('span'); segment.className = `csm-meter-${key}`;
                    segment.style.flexGrow = String(payload ? timeline.components[key] || 0 : 1); segment.style.flexBasis = '0';
                    segment.title = `${names[key]}: ${known ? size(timeline.components[key] || 0) : 'measuring'}`; el('segments').append(segment);
                    const item = document.createElement('li'), dot = document.createElement('i'), label = document.createElement('span'), value = document.createElement('strong');
                    dot.className = `csm-meter-${key}`; dot.setAttribute('aria-hidden','true'); label.textContent = names[key]; value.textContent = known ? size(timeline.components[key] || 0) : '—';
                    item.append(dot,label,value); el('legend').append(item);
                }
            }
            const percent = this.phase === 'restored' || this.phase === 'failed' ? 0 : m.percent;
            el('bar').setAttribute('aria-valuetext', `${phase}${percent == null ? '' : `; about ${Math.round(percent)} percent staged`}`);
            if (percent == null) el('bar').removeAttribute('aria-valuenow'); else el('bar').setAttribute('aria-valuenow',String(Math.round(percent)));
            el('mask').style.left = `${percent ?? 0}%`; el('bar').dataset.state = this.phase;
            const latest = [...this.events].reverse().slice(0,8), eventSignature = latest.map(event => event.id).join(':');
            if (eventSignature !== this.eventSignature) {
                this.eventSignature = eventSignature; el('events').replaceChildren();
                for (const event of latest) {
                    const item = document.createElement('li'), time = document.createElement('time'), text = document.createElement('span'), component = document.createElement('code');
                    time.dateTime = new Date(event.updated * 1000).toISOString(); time.textContent = new Date(event.updated * 1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'});
                    text.textContent = event.error || event.failure?.error || event.phase; component.textContent = event.component || '';
                    item.dataset.failed = String(event.state === 'failed'); item.append(time,text,component); el('events').append(item);
                }
            }
        }
    }
    globalThis.CSMTransferProgress = TransferProgress;
    if (typeof module !== 'undefined' && module.exports) module.exports = {TransferProgress,duration};
})();
