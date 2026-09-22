import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ['theme','palette','options','canvas','status','preview'];
    static values = { state:Object,saveUrl:String,previewUrl:String,token:String };
    connect() {
        this.state=structuredClone(this.stateValue);this.dirty=false;this.busy=false;this.dragged=null;
        this.beforeUnload=e=>{if(this.dirty){e.preventDefault();e.returnValue='';}};
        window.addEventListener('beforeunload',this.beforeUnload);
        this.beforeVisit=e=>{if(this.dirty&&!window.confirm('Ungespeicherte Änderungen verwerfen?'))e.preventDefault();};document.addEventListener('turbo:before-visit',this.beforeVisit);
        this.themeTarget.replaceChildren();this.state.themes.forEach(t=>this.themeTarget.add(new Option(t.label,t.key)));this.render();
    }
    disconnect(){window.removeEventListener('beforeunload',this.beforeUnload);document.removeEventListener('turbo:before-visit',this.beforeVisit);}
    node(tag,text,className){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(className)n.className=className;return n;}
    button(text,callback){const b=this.node('button',text);b.type='button';b.addEventListener('click',callback);return b;}
    changed(){this.dirty=true;this.statusTarget.textContent='Ungespeicherte Änderungen.';}
    schemaFields(schema,values,parent,disabled=false){
        Object.entries(schema).forEach(([key,f])=>{
            const label=this.node('label',f.label);let c;
            if(key==='imageId'){c=this.node('select');c.add(new Option('Standardmotiv / kein Bild','0'));this.state.images.forEach(image=>c.add(new Option(image.title,String(image.id))));if(values[key]&&!this.state.images.some(i=>i.id===values[key]))c.add(new Option('Vorhandenes Bild #'+values[key],String(values[key])));c.value=String(values[key]??0);}
            else if(f.type==='choice'){c=this.node('select');f.choices.forEach(v=>c.add(new Option(v,v)));c.value=values[key]??f.default;}
            else if(f.type==='text'&&f.max>120){c=this.node('textarea');c.rows=4;c.maxLength=f.max;c.value=values[key]??f.default;}
            else{c=this.node('input');c.type=f.type==='bool'?'checkbox':f.type==='int'?'number':'text';if(f.type==='bool')c.checked=values[key]??f.default;else c.value=values[key]??f.default;if(f.min!==undefined)c.min=f.min;if(f.max!==undefined){c.max=f.max;c.maxLength=f.max;}}
            c.disabled=disabled;c.addEventListener('input',()=>{values[key]=f.type==='bool'?c.checked:f.type==='int'?Number(c.value):c.value;this.changed();});label.append(c);parent.append(label);
        });
    }
    render(){
        const doc=this.state.document,theme=this.state.themes.find(t=>t.key===doc.theme);
        this.themeTarget.value=doc.theme;this.paletteTarget.replaceChildren();this.optionsTarget.replaceChildren();this.canvasTarget.replaceChildren();this.schemaFields(this.state.optionSchema,doc.options,this.optionsTarget);
        this.state.widgets.forEach(def=>{const add=this.button('+ '+def.label,()=>{
            const region=theme.regions.find(r=>(r==='main'||r==='content')&&(!def.regions.length||def.regions.includes(r)))??theme.regions.find(r=>!def.regions.length||def.regions.includes(r));
            if(!region){this.statusTarget.textContent='Kein kompatibler Bereich.';return;}
            const config=Object.fromEntries(Object.entries(def.schema??this.state.widgetSchema).map(([k,f])=>[k,f.default]));
            doc.widgets.push({id:crypto.randomUUID(),type:def.key,region,enabled:true,config});this.changed();this.render();
        });add.disabled=!def.multiple&&doc.widgets.some(w=>w.type===def.key);this.paletteTarget.append(add);});
        const regions=[...new Set([...theme.regions,...doc.widgets.map(w=>w.region)])];
        regions.forEach(region=>{
            const zone=this.node('section',undefined,'editor-zone');zone.dataset.region=region;zone.append(this.node('h2',region+(theme.regions.includes(region)?'':' → wird beim Speichern zugeordnet')));
            zone.addEventListener('dragover',e=>e.preventDefault());zone.addEventListener('drop',e=>{e.preventDefault();if(this.busy)return;const w=doc.widgets.find(w=>w.id===this.dragged);if(w&&this.canMove(w,region)){w.region=region;this.changed();this.render();}this.dragged=null;});
            doc.widgets.filter(w=>w.region===region).forEach(w=>{
                const def=this.state.widgets.find(d=>d.key===w.type),card=this.node('article',undefined,'editor-widget');card.draggable=Boolean(def);card.dataset.widgetId=w.id;
                card.addEventListener('dragstart',e=>{if(this.busy){e.preventDefault();return;}this.dragged=w.id;e.dataTransfer.setData('text/plain',w.id);});card.append(this.node('h3',def?.label??w.type+' (derzeit nicht verfügbar)'));
                card.addEventListener('dragover',e=>e.preventDefault());card.addEventListener('drop',e=>{e.preventDefault();e.stopPropagation();if(this.busy)return;const moved=doc.widgets.find(x=>x.id===this.dragged);if(!moved||moved===w||!this.canMove(moved,w.region))return;moved.region=w.region;doc.widgets=doc.widgets.filter(x=>x!==moved);doc.widgets.splice(doc.widgets.indexOf(w),0,moved);this.dragged=null;this.changed();this.render();});
                const controls=this.node('div',undefined,'editor-widget-tools'),active=this.node('label','Aktiv'),check=this.node('input');check.type='checkbox';check.checked=w.enabled;check.disabled=!def;check.addEventListener('change',()=>{w.enabled=check.checked;this.changed();});active.append(check);controls.append(active);
                const regionLabel=this.node('label','Region'),select=this.node('select');regions.forEach(r=>{if(this.canMove(w,r)||r===w.region)select.add(new Option(r,r));});select.value=w.region;select.addEventListener('change',()=>{w.region=select.value;this.changed();this.render();});regionLabel.append(select);controls.append(regionLabel);
                controls.append(this.button('↑',()=>this.move(w,-1)),this.button('↓',()=>this.move(w,1)),this.button('Entfernen',()=>{if(!window.confirm('Dieses Widget entfernen?'))return;doc.widgets=doc.widgets.filter(x=>x.id!==w.id);this.changed();this.render();}));card.append(controls);
                const details=this.node('details');details.append(this.node('summary','Widget konfigurieren'));this.schemaFields(def?.schema??this.state.widgetSchema,w.config,details,!def);card.append(details);zone.append(card);
            });
            if(!doc.widgets.some(w=>w.region===region))zone.append(this.node('p','Leer. Widgets hierher ziehen.','editor-empty'));this.canvasTarget.append(zone);
        });
    }
    canMove(w,r){const t=this.state.themes.find(t=>t.key===this.state.document.theme),d=this.state.widgets.find(d=>d.key===w.type);return t.regions.includes(r)&&(!d||!d.regions.length||d.regions.includes(r));}
    move(w,direction){const rows=this.state.document.widgets,siblings=rows.filter(x=>x.region===w.region),other=siblings[siblings.indexOf(w)+direction];if(!other)return;const a=rows.indexOf(w),b=rows.indexOf(other);[rows[a],rows[b]]=[rows[b],rows[a]];this.changed();this.render();}
    themeChanged(){this.state.document.theme=this.themeTarget.value;this.changed();this.render();}
    async request(preview){
        if(this.busy)return;this.busy=true;const controls=[...this.element.querySelectorAll('button,input,select,textarea')],disabled=controls.map(c=>c.disabled);controls.forEach(c=>{c.disabled=true;});
        this.statusTarget.textContent=preview?'Vorschau wird geladen…':'Wird gespeichert…';
        try{
            const r=await fetch(preview?this.previewUrlValue:this.saveUrlValue,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':this.tokenValue},body:JSON.stringify({version:this.state.version,document:this.state.document})});
            if(!r.ok){let message='Anfrage fehlgeschlagen. Bitte Anmeldung und Verbindung prüfen.';try{const e=await r.json();message=e.error??message;}catch{}throw new Error(message);}
            if(preview){this.previewTarget.srcdoc=await r.text();this.statusTarget.textContent='Vorschau geladen. Noch nicht veröffentlicht.';}
            else{const result=await r.json();this.state.document=result.document;this.state.version=result.version;this.dirty=false;this.render();this.statusTarget.textContent='Gespeichert und veröffentlicht. '+result.notices.join(' ');}
        }catch(e){this.statusTarget.textContent=e.message+' Deine Eingaben bleiben erhalten.';}
        finally{this.busy=false;controls.forEach((c,i)=>{c.disabled=disabled[i];});}
    }
    save(){return this.request(false);}preview(){return this.request(true);}
    viewport(e){this.previewTarget.style.width=e.currentTarget.dataset.width;}
}
