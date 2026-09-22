import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    connect(){this.group=this.element.querySelector('.portal-header-group');this.meta=this.element.querySelector('.portal-meta');this.observer=new ResizeObserver(()=>this.measure());this.observer.observe(this.meta);this.measure();}
    measure(){this.group.style.setProperty('--meta-height',`${this.meta.getBoundingClientRect().height}px`);}
    disconnect(){this.observer.disconnect();}
}
