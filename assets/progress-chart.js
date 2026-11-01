/* Locally bundled Chart.js; numeric status remains usable if canvas is unavailable. */
(() => {
    'use strict';
    const duration = seconds => {
        const m=Math.floor(seconds/60), s=Math.floor(seconds%60);
        return m ? `${m}m ${s}s` : `${s}s`;
    };
    const bytes = value => value>=1073741824?`${(value/1073741824).toFixed(2)} GiB`:`${(value/1048576).toFixed(1)} MiB`;
    class ProgressChart {
        constructor(canvas, reading) {
            if (!canvas || !globalThis.CSMChart) return;
            this.canvas=canvas; this.reading=reading; this.index=-1;
            const reduced=globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            this.chart=new globalThis.CSMChart(canvas, {
                type:'line',
                plugins:[{id:'trickleByteRanges',beforeDatasetsDraw:chart=>{
                    const state=this.context, area=chart.chartArea, x=chart.scales.x;
                    if(!state?.total||!area)return;
                    const ctx=chart.ctx, height=area.bottom-area.top;
                    ctx.save();ctx.beginPath();ctx.rect(area.left,area.top,area.right-area.left,height);ctx.clip();
                    ctx.fillStyle=state.pending;ctx.fillRect(area.left,area.top,area.right-area.left,height);
                    const ranges=state.windows.length?state.windows:[{startBytes:0,endBytes:state.total,receivedEnd:state.received}];
                    for(const range of ranges){
                        const left=x.getPixelForValue(range.startBytes), end=x.getPixelForValue(range.receivedEnd);
                        ctx.fillStyle=range.verified?state.verifiedColor:state.wash;ctx.fillRect(left,area.top,Math.max(0,end-left),height);
                        if(range.startBytes>0){ctx.strokeStyle=state.boundary;ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(left,area.top);ctx.lineTo(left,area.bottom);ctx.stroke();}
                    }
                    const edge=x.getPixelForValue(state.received);
                    ctx.strokeStyle=state.color;ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(edge,area.top);ctx.lineTo(edge,area.bottom);ctx.stroke();ctx.restore();
                }}],
                data:{datasets:[{data:[],parsing:false,borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,.08)',fill:false,borderWidth:2,pointRadius:0,pointHoverRadius:4,tension:.18}]},
                options:{
                    responsive:true, maintainAspectRatio:false, normalized:false,
                    animation:reduced?false:{duration:250,easing:'easeOutQuart'},
                    animations:{x:{duration:0}},
                    interaction:{mode:'nearest',axis:'x',intersect:false},
                    onHover:(event,elements)=>{if(elements.length)this.describe(elements[0].index);if(event&&this.context?.total){this.pointerPosition=this.chart.scales.x.getValueForPixel(event.x);this.describePosition(this.chart.scales.x.getValueForPixel(event.x));}},
                    plugins:{
                        legend:{display:false},
                        tooltip:{filter:()=>this.pointerPosition==null||this.pointerPosition<=this.context.received,displayColors:false,backgroundColor:'#1d2327',padding:10,callbacks:{
                            title:items=>`${this.format(items[0].raw.x)}${this.context.total?' · '+Math.min(100,items[0].raw.x/this.context.total*100).toFixed(1)+'%':''}`,
                            label:item=>`${item.raw.y.toFixed(1)} MB/s`,
                            afterLabel:item=>`Elapsed ${duration(item.raw.elapsed)} · ${item.raw.kind==='files'?'files':'database'}${this.rangeLabel(item.raw.x)?' · '+this.rangeLabel(item.raw.x):''}`
                        }}
                    },
                    scales:{
                        x:{type:'linear',min:0,grid:{display:false},border:{display:false},ticks:{maxTicksLimit:5,color:'#646970',font:{size:11},callback:value=>this.format(value)}},
                        y:{beginAtZero:true,suggestedMax:1,border:{display:false},grid:{color:'#eef0f2'},ticks:{maxTicksLimit:4,color:'#646970',font:{size:11},callback:value=>`${value} MB/s`}}
                    }
                }
            });
            canvas.addEventListener('keydown',event=>{
                if(!['ArrowLeft','ArrowRight','Escape','Home','End'].includes(event.key)||!this.samples?.length)return;
                event.preventDefault();this.pointerPosition=null;
                if(event.key==='Escape'){
                    this.chart.setActiveElements([]);this.chart.tooltip.setActiveElements([],{x:0,y:0});this.chart.update('none');return;
                }
                this.index=event.key==='Home'?0:event.key==='End'?this.samples.length-1:Math.max(0,Math.min(this.samples.length-1,(this.index<0?this.samples.length-1:this.index)+(event.key==='ArrowLeft'?-1:1)));
                const point=this.chart.getDatasetMeta(0).data[this.index];
                this.chart.setActiveElements([{datasetIndex:0,index:this.index}]);
                this.chart.tooltip.setActiveElements([{datasetIndex:0,index:this.index}],{x:point.x,y:point.y});
                this.chart.update('none');this.describe(this.index);
            });
        }
        describe(index) {
            this.index=index;
            const p=this.samples[index];
            if(p&&this.reading)this.reading.textContent=`${duration(p.elapsed)} · ${p.y.toFixed(1)} MB/s · ${bytes(p.x)} received${this.rangeLabel(p.x)?' · '+this.rangeLabel(p.x):''}`;
        }
        rangeLabel(position) {
            const range=this.context?.windows.find(r=>position>=r.startBytes&&position<r.endBytes) || (position===this.context?.total?this.context.windows.at(-1):null);
            return range?`${range.kind==='files'?'Files':'Database'} · ${bytes(range.startBytes)}–${bytes(range.endBytes)} · ${range.verified?'verified':range.state}`:'';
        }
        format(value) { return this.context?.unit==='bytes'?bytes(value):Math.round(value).toLocaleString(); }
        describePosition(position) {
            if(!this.reading)return;
            position=Math.max(0,Math.min(this.context.total,position));
            if(this.context.unit!=='bytes'){this.reading.textContent=`${this.format(position)} ${this.context.unit} · ${position<=this.context.received?'processed':'pending'}`;return;}
            const sample=this.samples.reduce((best,p)=>!best||Math.abs(p.x-position)<Math.abs(best.x-position)?p:best,null);
            this.reading.textContent=[bytes(position),this.rangeLabel(position),position>this.context.received?'Not received yet.':sample?`${sample.y.toFixed(1)} MB/s at ${bytes(sample.x)}`:'Speed appears as bytes arrive.'].filter(Boolean).join(' · ');
        }
        update(samples,phase,context={}) {
            if(!this.chart)return;
            this.context={total:context.total||0,received:context.received||0,windows:context.windows||[],unit:context.unit||'bytes',color:context.color||'#2271b1',wash:context.wash||'#dbeaf6',pending:context.pending||'#f6f7f7',verifiedColor:context.verifiedColor||'#eee3f4',grid:context.grid||'#eef0f2',tick:context.tick||'#646970',boundary:context.boundary||'#fff',errorColor:context.errorColor||'#b32d2e'};
            const signature=`${samples.length}:${samples.at(-1)?.at}:${phase}:${context.total}:${context.received}:${context.unit}:${context.stage}:${JSON.stringify(this.context)}:${context.windows?.map(r=>r.verified?1:0).join('')}`;
            if(signature===this.signature)return;
            const incremental=this.sampleCount!=null && samples.length===this.sampleCount+1;
            this.sampleCount=samples.length;
            this.samples=samples.map(p=>({...p,elapsed:p.x,x:p.position??(context.offsets?.[p.kind]||0)+p.bytes}));this.signature=signature;
            this.chart.data.datasets[0].data=this.samples;
            this.chart.options.scales.x.max=context.total||Math.max(1,...this.samples.map(p=>p.x));
            this.chart.data.datasets[0].borderColor=['failed','recover'].includes(phase)?this.context.errorColor:this.context.color;
            this.chart.options.scales.x.ticks.color=this.context.tick;this.chart.options.scales.y.ticks.color=this.context.tick;this.chart.options.scales.y.grid.color=this.context.grid;
            this.chart.options.scales.y.display=this.context.unit==='bytes';
            this.chart.options.scales.y.suggestedMax=Math.max(.1,...this.samples.map(point=>point.y))*1.15;
            this.chart.options.scales.x.ticks.precision=this.context.unit==='bytes'?undefined:0;
            this.chart.update(incremental?undefined:'none');
            if(!samples.length&&this.reading)this.reading.textContent=this.context.unit==='bytes'?'Shading shows received bytes. Speed appears as data arrives.':'Shading shows completed work. Hover to inspect.';
            else if(this.index<0&&this.reading)this.reading.textContent='Hover over the chart or focus it and use arrow keys.';
        }
        destroy() { this.chart?.destroy(); }
    }
    globalThis.CSMProgressChart=ProgressChart;
})();
