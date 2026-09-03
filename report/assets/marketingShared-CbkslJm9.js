import{f as me,r as C,a as G,j as t,F as pe,c as ue,w as O,t as he,b as ge,C as xe,E as ye,g as fe}from"./index-BOHyaEv4.js";import{r as ve}from"./index-UFiCzsma.js";import{F as ee}from"./file-text-2EKkHrA6.js";import{P as be}from"./pencil-Mr_hgw2w.js";const ke=me("CircleX",[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"m15 9-6 6",key:"1uzhvr"}],["path",{d:"m9 9 6 6",key:"z0biqf"}]]),te=[["pending_approval","Pending Approval"],["pending","In Marketing"],["completed","Completed"],["rejected","Rejected"]];function ne(e){return String(e?.take?.status||e?.status||"")}function L(e){return["pending","completed"].includes(ne(e))}function Y(e){const n=ne(e);return n==="pending_approval"?"Cannot print delivery note while the request is pending approval.":n==="rejected"?"Cannot print delivery note for a rejected request.":L(e)?"":"Cannot print delivery note for this request."}function je({children:e}){return C.useEffect(()=>{const n=document.body.style.overflow;return document.body.style.overflow="hidden",()=>{document.body.style.overflow=n}},[]),ve.createPortal(e,document.body)}function Z(e){return String(e).padStart(2,"0")}function A(e=new Date){return`${e.getFullYear()}-${Z(e.getMonth()+1)}-${Z(e.getDate())}`}const _e=()=>A(new Date);function ae(e=new Date){const n=new Date(e.getFullYear(),e.getMonth(),1),s=new Date(e.getFullYear(),e.getMonth()+1,0);return{from:A(n),to:A(s)}}function Ne(e=new Date){const n=new Date(e.getFullYear(),e.getMonth()-1,1),s=new Date(e.getFullYear(),e.getMonth(),0);return{from:A(n),to:A(s)}}function o(e){const n=Number(e||0);return Number.isFinite(n)?Math.round(n).toLocaleString():"0"}function Qe(e){if(e===""||e==null)return"";const n=Number(e);return Number.isFinite(n)?String(Math.round(n)):""}function K(e){if(!e)return"-";const n=String(e).trim(),s=n.match(/^(\d{4}-\d{2}-\d{2})/);if(s){const[i,r,m]=s[1].split("-").map(Number);return new Date(i,r-1,m).toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}const a=new Date(n.replace(" ","T"));return Number.isNaN(a.getTime())?n:a.toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}function $e(e){const n=String(e||"").trim().match(/^(\d{4}-\d{2}-\d{2})/);return n?n[1]:""}function we(e,n,s){return!n||!s?e:(e||[]).filter(a=>{const i=$e(a.event_date);return i&&i>=n&&i<=s})}function Se(e,n){return{action:"report",mode:e,include_items:1,date_from:n.from,date_to:n.to,status:n.status,marketing_type:n.marketing_type,product_id:n.product_id,created_by:n.created_by,q:n.q}}function Te(e){if(!e)return"-";const n=new Date(String(e).replace(" ","T"));return Number.isNaN(n.getTime())?e:n.toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}function qe(e){if(!e)return"-";const n=new Date(String(e).replace(" ","T"));return Number.isNaN(n.getTime())?e:n.toLocaleDateString("km-KH",{day:"numeric",month:"long",year:"numeric"})}function se(e){return e==="completed"?"success":e==="rejected"?"danger":e==="pending"?"info":"warning"}function l(e){return String(e??"").replace(/[&<>"']/g,n=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[n])}function I(e){return e==null||e===""?"-":e}const U=[["ugc_sample","UGC Sample"],["influencer_kol","Influencer / KOL"],["event","Event"],["giveaway","Giveaway"],["promotion","Promotion"],["sponsorship","Sponsorship"],["internal_use","Internal Use"],["other","Other"]];function T(e,n=U){const a=n.map(i=>Array.isArray(i)?i:[i.value,i.label]).find(([i])=>i===e);return a?a[1]:I(e)}function We(){return{event_name:"",marketing_type:"",delivery_type_id:"",event_date:_e(),location:"",phone:"",notes:"",existingImages:[],imageFiles:[],items:[{product_id:"",quantity:""}]}}const z=[["No.",(e,n)=>n+1],["Code",e=>e.display_code],["Event",e=>e.event_name],["Type",e=>e.marketing_type_label||T(e.marketing_type)],["Location",e=>e.location],["Phone",e=>e.phone],["Marketing Date",e=>K(e.event_date)],["Status",e=>e.status_label],["Items",e=>o(e.item_count)],["Taken",e=>o(e.total_taken)],["Returned",e=>o(e.total_returned)],["Created By",e=>e.created_by_name]],Ce=[["No.",(e,n)=>n+1],["Product",e=>e.product_name],["SKU",e=>e.sku],["Events",e=>o(e.event_count)],["Pending",e=>o(e.pending_qty)],["Taken",e=>o(e.total_taken)],["Returned",e=>o(e.total_returned)],["Not Returned",e=>o(e.total_not_returned)],["Remaining",e=>o(e.remaining_qty)],["Status",e=>e.status_label]];function V(e){return String(e||"").split(",").map(n=>n.trim()).filter(Boolean)}function Xe(e="all"){const[n,s]=C.useState(()=>{const b=ae();return{from:b.from,to:b.to,status:"",marketing_type:"",product_id:"",created_by:"",q:""}}),[a,i]=C.useState({products:[],creators:[],locations:[],delivery_types:[],marketing_types:U.map(([b,S])=>({value:b,label:S}))}),[r,m]=C.useState([]),[d,g]=C.useState([]),[h,x]=C.useState({}),[u,v]=C.useState({}),[k,p]=C.useState(!0),[j,y]=C.useState(""),[_,N]=C.useState(""),$=C.useRef(0);async function c(b=n){const S=++$.current;p(!0),y("");try{const[q,E]=await Promise.all([G("/api/reports/marketing.php",{action:"options"}),G("/api/reports/marketing.php",Se(e,b))]);if(S!==$.current)return;i({products:q.products||[],creators:q.creators||[],locations:q.locations||[],delivery_types:q.delivery_types||[],marketing_types:q.marketing_types?.length?q.marketing_types:U.map(([de,ce])=>({value:de,label:ce}))}),m(we(E.rows||[],b.from,b.to)),g(E.products||[]),x(E.counts||{}),v(E.type_counts||{})}catch(q){if(S!==$.current)return;y(q.message||"Unable to load marketing data.")}finally{S===$.current&&p(!1)}}C.useEffect(()=>{const b=window.setTimeout(()=>c(n),180);return()=>window.clearTimeout(b)},[e,n.from,n.to,n.status,n.marketing_type,n.product_id,n.created_by,n.q]);function f(b,S){s(q=>({...q,[b]:S}))}function w(b,S){s(q=>({...q,from:b,to:S}))}function R(b){f("status",n.status===b?"":b)}function D(b){const S=V(n.marketing_type),q=S.includes(b)?S.filter(E=>E!==b):[...S,b];f("marketing_type",q.join(","))}const F=V(n.marketing_type).map(b=>T(b,a.marketing_types)).filter(Boolean),B=n.product_id?a.products.find(b=>String(b.value)===String(n.product_id))?.label:"",M=`${n.from||"-"} to ${n.to||"-"}${n.status?` | ${te.find(([b])=>b===n.status)?.[1]||n.status}`:""}${F.length?` | ${F.join(", ")}`:""}${B?` | Product: ${B}`:""}`;return{filters:n,setFilter:f,applyRange:w,toggleStatus:R,toggleType:D,options:a,rows:r,products:d,counts:h,typeCounts:u,loading:k,error:j,setError:y,notice:_,setNotice:N,load:c,subtitle:M}}function Je({notice:e,error:n}){return t.jsxs(t.Fragment,{children:[e?t.jsx("div",{className:"marketing-report-alert success",children:e}):null,n?t.jsx("div",{className:"marketing-report-alert",children:n}):null]})}function Ze({counts:e,active:n,onToggle:s}){const a=[["pending_approval","Pending Approval",e.pending_approval||0],["pending","In Marketing",e.pending||0],["completed","Completed",e.completed||0]];return t.jsx("section",{className:"marketing-report-stats","aria-label":"Marketing summary",children:a.map(([i,r,m])=>t.jsxs("button",{type:"button",className:n===i?"active":"",onClick:()=>s(i),children:[t.jsx("span",{children:r}),t.jsx("strong",{children:o(m)})]},i))})}function et({filters:e,setFilter:n,applyRange:s,onRefresh:a,loading:i,showStatus:r=!0,showProductFilters:m=!1,marketingTypes:d=U,productOptions:g=[],creatorOptions:h=[],typeCounts:x={},onToggleType:u}){const v=ae(),k=Ne(),p=e.from===v.from&&e.to===v.to?"current":e.from===k.from&&e.to===k.to?"last":"",j=V(e.marketing_type),y=Object.values(x||{}).reduce((c,f)=>c+Number(f||0),0),_=j.length?j.length===1?`${T(j[0],d)} (${o(x?.[j[0]]||0)})`:`${j.length} types`:`All types (${o(y)})`;function N(c){s?s(c.from,c.to):(n("from",c.from),n("to",c.to))}function $(c){if(u){u(c);return}const f=j.includes(c)?j.filter(w=>w!==c):[...j,c];n("marketing_type",f.join(","))}return t.jsxs("div",{className:"marketing-report-filters",children:[t.jsxs("div",{className:"marketing-date-presets",children:[t.jsx("span",{children:"Marketing Date"}),t.jsxs("div",{children:[t.jsx("button",{type:"button",className:p==="current"?"active":"",onClick:()=>N(v),children:"This month"}),t.jsx("button",{type:"button",className:p==="last"?"active":"",onClick:()=>N(k),children:"Last month"})]})]}),t.jsxs("div",{className:"marketing-date-range",children:[t.jsxs("label",{className:"marketing-date-field",children:[t.jsx("span",{children:"From"}),t.jsx("input",{type:"date",value:e.from,onChange:c=>n("from",c.target.value)})]}),t.jsxs("label",{className:"marketing-date-field",children:[t.jsx("span",{children:"To"}),t.jsx("input",{type:"date",value:e.to,onChange:c=>n("to",c.target.value)})]})]}),r?t.jsxs("label",{className:"marketing-status-field",children:[t.jsx("span",{children:"Status"}),t.jsxs("select",{value:e.status,onChange:c=>n("status",c.target.value),children:[t.jsx("option",{value:"",children:"All status"}),te.map(([c,f])=>t.jsx("option",{value:c,children:f},c))]})]}):null,t.jsxs("div",{className:"marketing-type-field",children:[t.jsx("span",{children:"Type"}),t.jsxs("details",{className:"marketing-checkbox-filter",children:[t.jsx("summary",{children:_}),t.jsxs("div",{className:"marketing-checkbox-filter-menu",role:"group","aria-label":"Filter by marketing type",children:[t.jsxs("label",{className:j.length?"":"active",children:[t.jsx("input",{type:"checkbox",checked:!j.length,onChange:()=>n("marketing_type","")}),t.jsx("em",{children:"All types"}),t.jsx("strong",{children:o(y)})]}),d.map(c=>{const[f,w]=Array.isArray(c)?c:[c.value,c.label],R=Array.isArray(c)?"":c.image_url||"",D=j.includes(f);return t.jsxs("label",{className:D?"active":"",children:[t.jsx("input",{type:"checkbox",checked:D,onChange:()=>$(f)}),R?t.jsx("img",{src:R,alt:"",className:"marketing-type-thumb"}):null,t.jsx("em",{children:w}),t.jsx("strong",{children:o(x?.[f]||0)})]},f)})]})]})]}),m?t.jsxs("label",{className:"marketing-status-field",children:[t.jsx("span",{children:"By product"}),t.jsxs("select",{value:e.product_id||"",onChange:c=>n("product_id",c.target.value),children:[t.jsx("option",{value:"",children:"All products"}),(g||[]).map(c=>t.jsx("option",{value:c.value,children:c.label},c.value))]})]}):null,t.jsxs("div",{className:"marketing-search-row",children:[t.jsxs("label",{className:"marketing-search",children:[t.jsx("span",{children:"Search"}),t.jsxs("div",{children:[t.jsx(he,{size:15}),t.jsx("input",{value:e.q,onChange:c=>n("q",c.target.value),placeholder:"Code, event, creator..."})]})]}),t.jsxs("button",{type:"button",className:"marketing-refresh-btn",onClick:()=>a(),disabled:i,"aria-label":"Refresh",children:[i?t.jsx(O,{className:"spin",size:16}):t.jsx(ge,{size:16})," ",t.jsx("span",{children:"Refresh"})]})]})]})}function P(e,n){return e.reduce((s,a)=>s+Number(a[n]||0),0)}function H(e=[]){return{items:P(e,"item_count"),taken:P(e,"total_taken"),returned:P(e,"total_returned")}}function ie(e){return e==="pending"||e==="completed"}function Q(e=[],{includePending:n=!0}={}){return{events:P(e,"event_count"),pending:n?P(e,"pending_qty"):0,taken:P(e,"total_taken"),returned:P(e,"total_returned"),notReturned:P(e,"total_not_returned"),remaining:P(e,"remaining_qty")}}function W(e){return e.status_label?e.status_label:Number(e.pending_qty||0)>0?"Pending":Number(e.remaining_qty||0)>0?"In Marketing":"Done"}function Re(e){return e==="done"?"success":e==="pending"?"warning":"info"}function Me(e){const n=e.types||e.by_type||e.type_breakdown||e.marketing_types;return Array.isArray(n)&&n.length?n.map(s=>typeof s=="object"?s:{marketing_type:s}):n&&typeof n=="object"?Object.entries(n).map(([s,a])=>typeof a=="object"&&a?{marketing_type:s,...a}:{marketing_type:s,total_taken:a}):[]}function Pe(e=[],n=[]){const s=[];return e.forEach(a=>{const i=Me(a);if(i.length){i.forEach(r=>{const m=r.marketing_type||r.type||a.marketing_type||"other";s.push({...a,...r,marketing_type:m,marketing_type_label:r.marketing_type_label||r.type_label||T(m,n)})});return}(a.marketing_type||a.marketing_type_label)&&s.push(a)}),s}function De(e=[],n=[]){const s=Pe(e,n);if(!s.length)return null;const a=new Map;return s.forEach(i=>{const r=String(i.marketing_type||"other");a.has(r)||a.set(r,{key:r,label:i.marketing_type_label||T(r,n),rows:[]}),a.get(r).rows.push(i)}),Array.from(a.values())}function Ee(e){return e?.items||e?.products||e?.take_items||[]}function ze(e=[],n=[]){const s=new Map;return e.forEach(a=>{if(a.status==="rejected")return;const i=Ee(a);if(!i.length)return;const r=String(a.marketing_type||"other"),m=a.marketing_type_label||T(r,n);s.has(r)||s.set(r,{key:r,label:m,products:new Map});const d=s.get(r).products,g=a.status==="pending_approval",h=ie(a.status);i.forEach(x=>{const u=String(x.product_id||x.id||x.product_name||"");if(!u)return;const v=Number(x.quantity_taken||x.quantity||0),k=Number(x.quantity_returned||0),p=Number(x.quantity_not_returned||0),j=Number(x.remaining_qty||Math.max(0,v-k-p)),y=d.get(u)||{product_id:u,product_name:x.product_name||"-",sku:x.sku||"",event_count:0,pending_qty:0,total_taken:0,total_returned:0,total_not_returned:0,remaining_qty:0,marketing_type:r,marketing_type_label:m};y.event_count+=1,g?y.pending_qty+=v:h&&(y.total_taken+=v,y.total_returned+=k,y.total_not_returned+=p,y.remaining_qty+=j),d.set(u,y)})}),s.size?Array.from(s.values()).map(a=>({key:a.key,label:a.label,rows:Array.from(a.products.values()).map(i=>({...i,status:i.pending_qty>0?"pending":i.remaining_qty>0?"open":"done",status_label:W(i)}))})):null}function Le(e=[],n=[]){const s=new Map;return e.forEach(a=>{if(a.status==="rejected")return;const i=String(a.marketing_type||"other"),r=s.get(i)||{marketing_type:i,product_id:`type-${i}`,product_name:a.marketing_type_label||T(i,n),sku:"",event_count:0,pending_qty:0,total_taken:0,total_returned:0,total_not_returned:0,remaining_qty:0,item_count:0},m=Number(a.total_taken||0),d=Number(a.total_returned||0),g=Number(a.total_not_returned||0),h=Number(a.remaining_qty||Math.max(0,m-d-g));r.event_count+=1,r.item_count+=Number(a.item_count||0),a.status==="pending_approval"?r.pending_qty+=m:ie(a.status)&&(r.total_taken+=m,r.total_returned+=d,r.total_not_returned+=g,r.remaining_qty+=h),s.set(i,r)}),Array.from(s.values()).map(a=>{const i=a.remaining_qty||Math.max(0,a.total_taken-a.total_returned-a.total_not_returned);return{...a,remaining_qty:i,status:a.pending_qty>0?"pending":i>0?"open":"done",status_label:W({...a,remaining_qty:i})}})}function tt(e,n=[],s=[],a=[]){if(e!=="type")return{rows:n,groups:null,nameLabel:"Product",showTypeColumn:!1};const i=De(n,a),r=i?null:ze(s,a),m=i||r;return m?{rows:m.flatMap(d=>d.rows),groups:null,nameLabel:"Product",showTypeColumn:!0}:{rows:Le(s,a),groups:null,nameLabel:"Type",showTypeColumn:!1}}function nt(e=[],n){const[s,a]=C.useState([]),[i,r]=C.useState(!1),m=e.filter(L),d=m.map(y=>String(y.id)),g=new Set(s),h=d.filter(y=>g.has(y)),x=m.filter(y=>g.has(String(y.id))),u=d.length>0&&h.length===d.length,v=h.length>0&&!u;function k(y,_,N){const $=String(y),c=N||e.find(f=>String(f.id)===$);if(_&&c&&!L(c)){n?.(Y(c));return}a(f=>{const w=f.includes($);return _&&!w?[...f,$]:!_&&w?f.filter(R=>R!==$):f})}function p(y){a(y?d:[])}async function j(){if(!x.length){n?.("Select at least one approved request to print a delivery note.");return}n?.(""),r(!0);try{await Ue(x)}catch(y){n?.(y.message||"Unable to print delivery note.")}finally{r(!1)}}return{selectedIds:h,selectedRows:x,allSelected:u,someSelected:v,printing:i,toggleSelected:k,toggleAll:p,printSelectedDeliveryNotes:j}}function at({rows:e,loading:n,emptyText:s="Try changing the filter.",renderActions:a,selectedIds:i,allSelected:r,someSelected:m,onToggleSelected:d,onToggleAll:g,onViewImages:h}){if(n)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(O,{className:"spin",size:26}),t.jsx("strong",{children:"Loading marketing data..."})]});if(!e.length)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(xe,{size:28}),t.jsx("strong",{children:"No marketing requests found."}),t.jsx("span",{children:s})]});const x=H(e),u=typeof d=="function",v=new Set((i||[]).map(String)),k=e.filter(L).length;return t.jsx("div",{className:"marketing-table-wrap",children:t.jsxs("table",{className:"marketing-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[u?t.jsx("th",{className:"col-check",children:t.jsx("input",{type:"checkbox",checked:!!r,disabled:k<1,ref:p=>{p&&(p.indeterminate=!!m)},onChange:()=>g?.(!r),"aria-label":"Select all approved requests",title:k<1?"Pending requests cannot print a delivery note.":"Select all approved requests"})}):null,t.jsx("th",{className:"hide-sm",children:"No."}),t.jsx("th",{children:"Code"}),t.jsx("th",{children:"Event"}),t.jsx("th",{className:"hide-sm",children:"Type"}),t.jsx("th",{children:"Marketing Date"}),t.jsx("th",{children:"Status"}),t.jsx("th",{className:"num hide-md",children:"Items"}),t.jsx("th",{className:"num hide-md",children:"Taken"}),t.jsx("th",{className:"num hide-sm",children:"Returned"}),t.jsx("th",{className:"hide-sm",children:"Created By"}),t.jsx("th",{className:"col-image",children:"Image"}),t.jsx("th",{children:"Action"})]})}),t.jsx("tbody",{children:e.map((p,j)=>{const y=(p.image_urls||[]).length,_=L(p),N=_?"":Y(p);return t.jsxs("tr",{className:v.has(String(p.id))?"is-selected":void 0,children:[u?t.jsx("td",{className:"col-check",children:t.jsx("label",{className:`marketing-check-cell${_?"":" is-disabled"}`,title:N||"Select to print delivery note",children:t.jsx("input",{type:"checkbox",checked:v.has(String(p.id)),disabled:!_,onChange:$=>d(p.id,$.target.checked,p),"aria-label":`Select ${p.display_code||p.event_name||p.id}`})})}):null,t.jsx("td",{className:"col-no hide-sm",children:j+1}),t.jsx("td",{children:t.jsx("code",{children:p.display_code})}),t.jsxs("td",{children:[t.jsx("strong",{children:p.event_name}),t.jsx("span",{children:p.location||"-"}),t.jsx("span",{className:"show-sm marketing-row-extra",children:p.marketing_type_label||T(p.marketing_type)})]}),t.jsx("td",{className:"hide-sm",children:p.marketing_type_label||T(p.marketing_type)}),t.jsx("td",{children:K(p.event_date)}),t.jsx("td",{children:t.jsx("span",{className:`marketing-status ${se(p.status)}`,children:p.status_label})}),t.jsx("td",{className:"num hide-md",children:o(p.item_count)}),t.jsx("td",{className:"num is-taken hide-md",children:o(p.total_taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:o(p.total_returned)}),t.jsx("td",{className:"hide-sm",children:p.created_by_name||"-"}),t.jsx("td",{className:"marketing-image-cell",children:y?t.jsxs("button",{type:"button",className:"marketing-image-view",title:"View image","aria-label":"View image",onClick:()=>h?.(p),children:[t.jsx("img",{src:p.image_url||p.image_urls?.[0],alt:""}),t.jsx("span",{children:y})]}):t.jsx("span",{className:"marketing-no-image",children:"-"})}),t.jsx("td",{className:"marketing-action-cell",children:a?.(p)})]},p.id)})}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[u?t.jsx("td",{className:"col-check"}):null,t.jsx("td",{className:"hide-sm"}),t.jsx("td",{className:"marketing-total-label",children:"Total"}),t.jsx("td",{}),t.jsx("td",{className:"hide-sm"}),t.jsx("td",{}),t.jsx("td",{}),t.jsx("td",{className:"num hide-md",children:o(x.items)}),t.jsx("td",{className:"num is-taken hide-md",children:o(x.taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:o(x.returned)}),t.jsx("td",{className:"hide-sm"}),t.jsx("td",{}),t.jsx("td",{})]})})]})})}function st({rows:e,loading:n,nameLabel:s="Product",showTypeColumn:a=!1,emptyText:i="Try changing the date or status filter."}){if(n)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(O,{className:"spin",size:26}),t.jsx("strong",{children:"Loading product report..."})]});if(!e.length)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(fe,{size:28}),t.jsxs("strong",{children:["No ",s==="Type"?"marketing types":"products"," found."]}),t.jsx("span",{children:i})]});const r=Q(e,{includePending:!a}),m=s==="Type";return t.jsx("div",{className:"marketing-table-wrap",children:t.jsxs("table",{className:"marketing-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[t.jsx("th",{className:"hide-sm",children:"No."}),t.jsx("th",{children:s}),m?null:t.jsx("th",{className:"hide-sm",children:"SKU"}),a?t.jsx("th",{children:"Marketing Type"}):null,t.jsx("th",{className:"num hide-md",children:"Events"}),t.jsx("th",{className:"num hide-md",children:"Pending"}),t.jsx("th",{className:"num",children:"Taken"}),t.jsx("th",{className:"num hide-sm",children:"Returned"}),t.jsx("th",{className:"num hide-sm",children:"Not Returned"}),t.jsx("th",{className:"num",children:"Remaining"}),t.jsx("th",{children:"Status"})]})}),t.jsx("tbody",{children:e.map((d,g)=>t.jsxs("tr",{children:[t.jsx("td",{className:"col-no hide-sm",children:g+1}),t.jsxs("td",{children:[t.jsx("strong",{children:d.product_name||"-"}),m?null:t.jsx("span",{className:"show-sm marketing-row-extra",children:d.sku||"-"})]}),m?null:t.jsx("td",{className:"hide-sm",children:d.sku||"-"}),a?t.jsx("td",{children:d.marketing_type_label||T(d.marketing_type)||"-"}):null,t.jsx("td",{className:"num hide-md",children:o(d.event_count)}),t.jsx("td",{className:"num is-pending hide-md",children:o(d.pending_qty)}),t.jsx("td",{className:"num is-taken",children:o(d.total_taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:o(d.total_returned)}),t.jsx("td",{className:"num hide-sm",children:o(d.total_not_returned)}),t.jsx("td",{className:"num is-remaining",children:o(d.remaining_qty)}),t.jsx("td",{children:t.jsx("span",{className:`marketing-status ${Re(d.status)}`,children:d.status_label||W(d)})})]},`${d.marketing_type||"all"}-${d.product_id||g}`))}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[t.jsx("td",{className:"hide-sm"}),t.jsx("td",{className:"marketing-total-label",children:"Total"}),m?null:t.jsx("td",{className:"hide-sm"}),a?t.jsx("td",{}):null,t.jsx("td",{className:"num hide-md",children:o(r.events)}),t.jsx("td",{className:"num is-pending hide-md",children:a?"-":o(r.pending)}),t.jsx("td",{className:"num is-taken",children:o(r.taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:o(r.returned)}),t.jsx("td",{className:"num hide-sm",children:o(r.notReturned)}),t.jsx("td",{className:"num is-remaining",children:o(r.remaining)}),t.jsx("td",{})]})})]})})}function X(e=[]){return e.reduce((n,s)=>({taken:n.taken+Number(s.quantity_taken||0),returned:n.returned+Number(s.quantity_returned||0),notReturned:n.notReturned+Number(s.quantity_not_returned||0),remaining:n.remaining+Number(s.remaining_qty||0)}),{taken:0,returned:0,notReturned:0,remaining:0})}function it({take:e,items:n,extraColumns:s,extraCells:a,extraFooter:i,children:r,footer:m,onClose:d}){const g=X(n),h=(e.image_urls||[]).filter(Boolean),x=[["Code",e.display_code||"-"],["Date",K(e.event_date)],["Type",e.marketing_type_label||T(e.marketing_type)],["Delivery",e.delivery_type_name||"-"],["Location",e.location||"-"],["Phone",e.phone||"-"],["Created By",e.created_by_name||"-"]];return t.jsx(je,{children:t.jsx("div",{className:"marketing-modal-backdrop",role:"presentation",onMouseDown:d,children:t.jsxs("section",{className:"marketing-modal",role:"dialog","aria-modal":"true","aria-label":"Marketing request detail",onMouseDown:u=>u.stopPropagation(),children:[t.jsxs("header",{className:"marketing-detail-header",children:[t.jsxs("div",{children:[t.jsx("code",{className:"marketing-detail-code",children:e.display_code||"-"}),t.jsxs("div",{className:"marketing-modal-title",children:[t.jsx("h3",{children:e.event_name||"Marketing request"}),t.jsx("span",{className:`marketing-status ${se(e.status)}`,children:e.status_label})]}),t.jsxs("p",{className:"marketing-modal-meta",children:[t.jsx("code",{children:e.display_code}),t.jsx("span",{children:K(e.event_date)}),t.jsx("span",{children:e.marketing_type_label||T(e.marketing_type)}),e.delivery_type_name?t.jsx("span",{children:e.delivery_type_name}):null,t.jsx("span",{children:e.location||"—"}),e.phone?t.jsx("span",{children:e.phone}):null,t.jsx("span",{children:e.created_by_name||"—"})]})]}),t.jsx("button",{type:"button",onClick:d,"aria-label":"Close",children:t.jsx(ke,{size:16})})]}),t.jsxs("div",{className:"marketing-modal-body",children:[t.jsx("section",{className:"marketing-detail-summary","aria-label":"Marketing request summary",children:x.map(([u,v])=>t.jsxs("div",{className:"marketing-detail-field",children:[t.jsx("span",{children:u}),t.jsx("strong",{children:v})]},u))}),h.length?t.jsxs("section",{className:"marketing-detail-section",children:[t.jsxs("div",{className:"marketing-detail-section-title",children:[t.jsx("strong",{children:"Images"}),t.jsx("span",{children:h.length})]}),t.jsx("div",{className:"marketing-detail-images",children:h.map((u,v)=>t.jsx("a",{href:u,target:"_blank",rel:"noreferrer","aria-label":`Open image ${v+1}`,children:t.jsx("img",{src:u,alt:""})},u))})]}):null,e.notes?t.jsxs("section",{className:"marketing-detail-section",children:[t.jsx("div",{className:"marketing-detail-section-title",children:t.jsx("strong",{children:"Note"})}),t.jsx("p",{className:"marketing-modal-note",children:e.notes})]}):null,t.jsxs("section",{className:"marketing-detail-section",children:[t.jsxs("div",{className:"marketing-detail-section-title",children:[t.jsx("strong",{children:"Products"}),t.jsx("span",{children:n.length})]}),t.jsx("div",{className:"marketing-table-wrap modal-table",children:t.jsxs("table",{className:"marketing-table marketing-detail-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[t.jsx("th",{children:"Product"}),t.jsx("th",{className:"num",children:"Taken"}),t.jsx("th",{className:"num",children:"Returned"}),t.jsx("th",{className:"num",children:"Not Returned"}),t.jsx("th",{className:"num",children:"Remaining"}),s]})}),t.jsx("tbody",{children:n.map(u=>t.jsxs("tr",{children:[t.jsxs("td",{children:[t.jsx("strong",{children:u.product_name}),u.sku?t.jsx("span",{children:u.sku}):null]}),t.jsx("td",{className:"num",children:o(u.quantity_taken)}),t.jsx("td",{className:"num",children:o(u.quantity_returned)}),t.jsx("td",{className:"num",children:o(u.quantity_not_returned)}),t.jsx("td",{className:"num",children:o(u.remaining_qty)}),a?.(u)]},u.id))}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[t.jsx("td",{children:"Total"}),t.jsx("td",{className:"num",children:o(g.taken)}),t.jsx("td",{className:"num",children:o(g.returned)}),t.jsx("td",{className:"num",children:o(g.notReturned)}),t.jsx("td",{className:"num",children:o(g.remaining)}),i]})})]})})]})]}),r,m]})})})}function re(e="Product",n=!1){const s=Ce.map((a,i)=>i===1?[e,a[1]]:a);return n?[...s.slice(0,3),["Marketing Type",a=>a.marketing_type_label||T(a.marketing_type)],...s.slice(3)]:s}function rt(e,n,s,a=[],i={}){const r=new Date().toLocaleString(),m=z.length,d=s.map((f,w)=>`<tr>${z.map(([,R])=>`<td>${l(I(R(f,w)))}</td>`).join("")}</tr>`).join(""),g=a,h=i.productColumns||re(i.productNameLabel,i.showTypeColumn),x=h.length,u=g.map((f,w)=>`<tr>${h.map(([,R])=>`<td>${l(I(R(f,w)))}</td>`).join("")}</tr>`).join(""),v=H(s),k=Q(g,{includePending:!i.showTypeColumn}),p=s.length?`
      <tr>
        <td>Total</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        <td>${l(o(v.items))}</td>
        <td>${l(o(v.taken))}</td>
        <td>${l(o(v.returned))}</td>
        <td></td>
      </tr>
  `:"",j=i.showTypeColumn?"<td>Total</td><td></td><td></td><td></td>":"<td>Total</td><td></td><td></td>",y=g.length?`
      <tr>
        ${j}
        <td>${l(o(k.events))}</td>
        <td>${l(i.showTypeColumn?"-":o(k.pending))}</td>
        <td>${l(o(k.taken))}</td>
        <td>${l(o(k.returned))}</td>
        <td>${l(o(k.notReturned))}</td>
        <td>${l(o(k.remaining))}</td>
        <td></td>
      </tr>
  `:"",_=g.length?`
    <br>
    <table border="1">
      <tr><th colspan="${x}">${l(i.productReportTitle||"Product Report")}</th></tr>
      <tr>${h.map(([f])=>`<th>${l(f)}</th>`).join("")}</tr>
      ${u}
      ${y}
    </table>
  `:"",N=`
    <table border="1">
      <tr><th colspan="${m}">${l(e)}</th></tr>
      <tr><td colspan="${m}">${l(n)}</td></tr>
      <tr><td colspan="${m}">Generated: ${l(r)}</td></tr>
      <tr>${z.map(([f])=>`<th>${l(f)}</th>`).join("")}</tr>
      ${d||`<tr><td colspan="${m}">No marketing requests found.</td></tr>`}
      ${p}
    </table>
    ${_}
  `,$=new Blob([`\uFEFF${N}`],{type:"application/vnd.ms-excel;charset=utf-8;"}),c=document.createElement("a");c.href=URL.createObjectURL($),c.download=`${e.toLowerCase().replace(/[^a-z0-9]+/g,"-")}-${new Date().toISOString().slice(0,10)}.xls`,document.body.appendChild(c),c.click(),URL.revokeObjectURL(c.href),c.remove()}function lt(e,n,s,a=[],i={}){document.getElementById("print-root")?.remove();const r=document.createElement("div");r.id="print-root";const m=s.map((_,N)=>`<tr>${z.map(([,$],c)=>`<td${c>=8&&c<=10?' class="num"':""}>${l(I($(_,N)))}</td>`).join("")}</tr>`).join(""),d=a,g=i.productColumns||re(i.productNameLabel,i.showTypeColumn),h=i.showTypeColumn?1:0,x=d.map((_,N)=>`<tr>${g.map(([,$],c)=>`<td${c>=3+h&&c<=8+h?' class="num"':""}>${l(I($(_,N)))}</td>`).join("")}</tr>`).join(""),u=H(s),v=Q(d,{includePending:!i.showTypeColumn}),k=s.length?`
        <tfoot>
          <tr>
            <td colspan="8">Total</td>
            <td class="num">${l(o(u.items))}</td>
            <td class="num">${l(o(u.taken))}</td>
            <td class="num">${l(o(u.returned))}</td>
            <td></td>
          </tr>
        </tfoot>
  `:"",p=d.length?`
      <h2>${l(i.productReportTitle||"Product Report")}</h2>
      <table>
        <thead><tr>${g.map(([_],N)=>`<th${N>=3+h&&N<=8+h?' class="num"':""}>${l(_)}</th>`).join("")}</tr></thead>
        <tbody>${x}</tbody>
        <tfoot>
          <tr>
            <td colspan="${3+h}">Total</td>
            <td class="num">${l(o(v.events))}</td>
            <td class="num">${l(i.showTypeColumn?"-":o(v.pending))}</td>
            <td class="num">${l(o(v.taken))}</td>
            <td class="num">${l(o(v.returned))}</td>
            <td class="num">${l(o(v.notReturned))}</td>
            <td class="num">${l(o(v.remaining))}</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
  `:"";r.innerHTML=`
    <div class="operation-stock-print marketing-report-print">
      <header class="operation-stock-print-header">
        <h1>${l(e)}</h1>
        <p class="operation-stock-print-generated">${l(n)}</p>
        <p class="operation-stock-print-generated">Generated: ${l(new Date().toLocaleString())}</p>
      </header>
      <table>
        <thead><tr>${z.map(([_],N)=>`<th${N>=8&&N<=10?' class="num"':""}>${l(_)}</th>`).join("")}</tr></thead>
        <tbody>${m||`<tr><td colspan="${z.length}">No marketing requests found.</td></tr>`}</tbody>
        ${k}
      </table>
      ${p}
    </div>
  `,document.body.appendChild(r);function j(){document.body.classList.remove("printing-panel","printing-marketing-report"),r.remove(),window.removeEventListener("afterprint",j),window.clearTimeout(y)}const y=window.setTimeout(j,6e4);document.body.classList.add("printing-panel","printing-marketing-report"),window.addEventListener("afterprint",j),window.print()}function Be(e){const n=e.take||{},s=e.items||[],a=e.settings||e.invoice_settings||{},i=a.company_name||"Shadow Group Co., Ltd.",r=a.company_phone||"",m=a.company_email||"",d=a.company_address||"",g=a.contact_person||"",h=e.logo_url||e.invoice_logo_url||a.logo_url||"",x=h?`${h}${h.includes("?")?"&":"?"}v=${Date.now()}`:"",u=Math.max(40,Math.min(200,Number(a.logo_width||80))),v=Math.max(40,Math.min(200,Number(a.logo_height||70))),k=n.status==="completed",p=n.display_code||`MT-#${n.id||""}`,j=X(s),y=n.marketing_type_label||T(n.marketing_type),_=k?'<th style="width:16%;">បានប្រគល់<span class="th-en">Returned</span></th><th style="width:16%;">មិនប្រគល់<span class="th-en">Not Returned</span></th>':"",N=s.map((M,b)=>`
    <tr>
      <td class="tc">${b+1}</td>
      <td><strong>${l(M.product_name||"-")}</strong>${M.sku?`<div class="sku">${l(M.sku)}</div>`:""}</td>
      <td class="tc">${l(o(M.quantity_taken))}</td>
      ${k?`<td class="tc">${l(o(M.quantity_returned))}</td><td class="tc">${l(o(M.quantity_not_returned))}</td>`:""}
    </tr>
  `).join("")||`<tr><td colspan="${k?5:3}" class="tc">គ្មានផលិតផល / No products</td></tr>`,$=k?`<td class="tc">${l(o(j.returned))}</td><td class="tc">${l(o(j.notReturned))}</td>`:"",c=[g?`<div>ទំនាក់ទំនង / Contact: ${l(g)}</div>`:"",d?`<div>${l(d).replace(/\n/g,"<br>")}</div>`:"",r?`<div>ទូរស័ព្ទ / Phone: ${l(r)}</div>`:"",m?`<div>អ៊ីមែល / Email: ${l(m)}</div>`:""].filter(Boolean).join(""),f=Te(n.event_date),w=qe(n.event_date),D=[["លេខវិក្កយបត្រ","Invoice No",p],["កាលបរិច្ឆេទ","Date",w===f?f:`${w} / ${f}`],["ព្រឹត្តិការណ៍","Event",n.event_name||"-"],["ទូរស័ព្ទ","Phone",n.phone||"-"],["ទីតាំង","Location",n.location||"-"],["ប្រភេទ","Type",y||"-"],["ប្រភេទដឹកជញ្ជូន","Delivery Type",n.delivery_type_name||"-"]].map(([M,b,S])=>`<tr><td><span class="lbl-km">${l(M)}</span><span class="lbl-en">${l(b)}</span></td><td>${l(S)}</td></tr>`).join(""),F=k?"វិក្កយបត្រផ្ទៀងផ្ទាត់ទីផ្សារ":"វិក្កយបត្រទីផ្សារ",B=k?"MARKETING RECONCILE":"MARKETING INVOICE";return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <title>${l(p)} ${B}</title>
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@400;700&amp;family=Noto+Sans+Khmer:wght@400;600;700;800&amp;display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #e5e7eb;
      color: #111827;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", Arial, system-ui, sans-serif;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .invoice-page {
      max-width: 820px;
      margin: 1.5rem auto;
      background: #fff;
      box-shadow: 0 4px 24px rgba(0,0,0,.13);
      overflow: hidden;
      padding: 32px 40px;
    }
    .inv-accent { background: #1e3a5f; height: 7px; margin: -32px -40px 24px; }
    .inv-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 18px; }
    .inv-top-left { flex: 1; min-width: 0; }
    .inv-top-right { flex: 0 0 46%; text-align: right; }
    .inv-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
    .logo { line-height: 1; }
    .logo img { max-width: ${u}px; max-height: ${v}px; object-fit: contain; display: block; }
    .inv-company { min-width: 0; }
    .inv-company-name { font-size: 17px; font-weight: 800; color: #111; margin-bottom: 4px; white-space: nowrap; }
    .inv-company-info { font-size: 13px; color: #111; line-height: 1.75; }
    .inv-title-km { font-size: 20px; font-weight: 800; color: #111; line-height: 1.25; }
    .inv-title-en { font-size: 13px; font-weight: 700; color: #111; letter-spacing: 1.5px; margin: 2px 0 10px; }
    .inv-info-tbl { margin-left: auto; font-size: 13px; border-collapse: collapse; border: 0; }
    .inv-info-tbl td { padding: 5px 8px; color: #111; text-align: left; line-height: 1.35; border: 0; vertical-align: top; }
    .inv-info-tbl td:first-child { font-weight: 700; white-space: nowrap; }
    .inv-info-tbl td:last-child { overflow-wrap: anywhere; font-weight: 600; }
    .inv-info-tbl tr:nth-child(odd) td { background: #e8eef5; }
    .inv-info-tbl tr:nth-child(even) td { background: #f8fafc; }
    .inv-info-tbl .lbl-km { display: block; }
    .inv-info-tbl .lbl-en { display: block; font-size: 11px; font-weight: 600; color: #334155; }
    .inv-divider { border: none; border-top: 1px solid #000; margin: 16px 0; }
    .inv-items { width: 100%; border-collapse: collapse; }
    .inv-items th {
      background: #1e3a5f;
      color: #fff;
      padding: 9px 12px;
      text-align: center;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #16304d;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .inv-items th .th-en { display: block; font-size: 10px; font-weight: 600; letter-spacing: 0.3px; opacity: 0.92; }
    .inv-items th.tl { text-align: left; }
    .inv-items td { padding: 8px 12px; border: 1px solid #000; font-size: 13px; color: #111; }
    .inv-items td.tc, .tc { text-align: center; }
    .inv-items tbody tr:nth-child(even) { background: #f3f6fa; }
    .inv-items .sku { margin-top: 2px; color: #4b5563; font-size: 11px; font-weight: 500; }
    .inv-items tfoot td { background: #e8eef5; font-weight: 800; }
    .inv-notes { min-height: 44px; padding: 10px 0 4px; font-size: 13px; color: #111; }
    .inv-signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 28px; margin-top: 56px; padding: 0 8px; }
    .inv-sig { text-align: center; font-size: 14px; font-weight: 800; color: #111; }
    .inv-sig .sig-en { display: block; font-size: 12px; font-weight: 700; margin-top: 2px; }
    .inv-sig-line { border-top: 1px solid #111; margin-top: 64px; }
    .inv-generated { margin-top: 16px; text-align: center; font-size: 11px; color: #111; padding-top: 8px; }
    @page { size: A4; margin: 10mm; }
    @media print {
      html, body { background: #fff; }
      .invoice-page { max-width: none; margin: 0; padding: 8px 12px; box-shadow: none; }
      .inv-accent { margin: -8px -12px 8px; }
      .inv-title-km { font-size: 18px; }
      .inv-title-en { font-size: 12px; }
      .inv-signatures { margin-top: 40px; }
      .inv-sig-line { margin-top: 48px; }
    }
  </style>
</head>
<body>
  <section class="invoice-page">
    <div class="inv-accent"></div>
    <div class="inv-top">
      <div class="inv-top-left">
        <div class="inv-brand">
          <div class="logo">${x?`<img id="marketing-invoice-logo" src="${l(x)}" alt="Logo">`:""}</div>
          <div class="inv-company">
            <div class="inv-company-name">${l(i)}</div>
            ${c?`<div class="inv-company-info">${c}</div>`:""}
          </div>
        </div>
      </div>
      <div class="inv-top-right">
        <div class="inv-title-km">${F}</div>
        <div class="inv-title-en">${B}</div>
        <table class="inv-info-tbl">${D}</table>
      </div>
    </div>
    <hr class="inv-divider">
    <table class="inv-items">
      <thead>
        <tr>
          <th class="tl" style="width:8%;">ល.រ<span class="th-en">No</span></th>
          <th class="tl">ផលិតផល<span class="th-en">Product</span></th>
          <th style="width:16%;">ចំនួនយក<span class="th-en">Qty Taken</span></th>
          ${_}
        </tr>
      </thead>
      <tbody>${N}</tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="tc">សរុប / TOTAL</td>
          <td class="tc">${l(o(j.taken))}</td>
          ${$}
        </tr>
      </tfoot>
    </table>
    <div class="inv-notes">${n.notes?`* ${l(n.notes)}`:""}</div>
    <div class="inv-signatures">
      <div class="inv-sig">រៀបចំ<span class="sig-en">Prepare</span><div class="inv-sig-line"></div></div>
      <div class="inv-sig">អនុម័ត<span class="sig-en">Approve</span><div class="inv-sig-line"></div></div>
      <div class="inv-sig">អ្នកទទួល<span class="sig-en">Receiver</span><div class="inv-sig-line"></div></div>
    </div>
    <div class="inv-generated">បង្កើតនៅ ${l(new Date().toLocaleString("km-KH"))} / Generated ${l(new Date().toLocaleString())} - សូមអរគុណ! / Thank you!</div>
  </section>
</body>
</html>`}function J(e,n){const s=document.createElement("iframe");s.style.cssText="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;",document.body.appendChild(s);const a=s.contentWindow,i=a?.document||s.contentDocument;if(!i){s.remove(),window.alert("Unable to open print window.");return}i.open(),i.write(e),i.close();const r=()=>{try{a?.focus?.(),a?.print?.()}catch(d){console.error("Print failed:",d)}window.setTimeout(()=>{try{s.remove()}catch{}},800)},m=()=>{const d=[...i.images||[]],g=n?i.getElementById(n):null;return g&&!d.includes(g)&&d.push(g),!d.length||d.every(h=>h.complete)?Promise.resolve():Promise.all(d.map(h=>new Promise(x=>{if(h.complete)return x();const u=()=>x();h.addEventListener("load",u,{once:!0}),h.addEventListener("error",u,{once:!0}),window.setTimeout(u,1500)})))};Promise.resolve().then(()=>i.fonts?.ready).catch(()=>{}).then(m).then(()=>window.setTimeout(r,80))}function Ae(e){J(Be(e||{}),"marketing-invoice-logo")}function le(e){const n=e.take||{},s=e.items||[],a=e.settings||e.invoice_settings||{},i=X(s),r=s.map((h,x)=>`
    <tr>
      <td class="no">${x+1}</td>
      <td class="product">${l(h.product_name||"-")}</td>
      <td class="qty">${l(o(h.quantity_taken))}</td>
    </tr>
  `).join("")||'<tr><td colspan="3" class="empty">No products.</td></tr>',m=e.logo_url||e.invoice_logo_url||a.logo_url||"",d=n.display_code||`MT-#${n.id||""}`,g=`https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=${encodeURIComponent(d)}`;return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delivery Slip - ${l(d)}</title>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;900&amp;family=Battambang:wght@400;700&amp;display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #e5e7eb;
      color: #111;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .receipt-card {
      max-width: 360px;
      margin: 1.5rem auto;
      border-radius: 1.25rem;
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
      background: #fff;
      border: 1px solid #e5e7eb;
      padding: 1rem;
    }
    .receipt-card + .receipt-card { margin-top: 2rem; }
    .receipt-header-logo {
      min-height: 32px;
      text-align: center;
      margin-bottom: 0.5rem;
    }
    .receipt-header-logo img {
      max-height: 60px;
      max-width: 100px;
      object-fit: contain;
    }
    .receipt-title {
      margin-bottom: 0.75rem;
      color: #000;
      font-size: 0.9rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-align: center;
      text-transform: uppercase;
    }
    .top-line {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 0.5rem;
      color: #000;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.35;
    }
    .code { margin-top: 2px; font-size: 13px; font-weight: 700; }
    .receipt-qr {
      width: 72px;
      height: 72px;
      object-fit: contain;
      flex: 0 0 72px;
    }
    .section-divider {
      border-top: 1px solid #000;
      margin: 0.6rem 0;
    }
    .section-title {
      margin-bottom: 0.25rem;
      color: #000;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .info-row {
      display: grid;
      grid-template-columns: 88px minmax(0, 1fr);
      gap: 8px;
      margin-bottom: 0.25rem;
      color: #000;
      font-size: 15px;
      line-height: 1.35;
    }
    .label-col, .value-col {
      color: #000;
      font-weight: 700;
      overflow-wrap: anywhere;
    }
    .delivery-items-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 0.25rem;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .delivery-items-table th,
    .delivery-items-table td {
      border: 1px solid #000;
      padding: 6px 8px;
      color: #111;
      font-size: 13px;
      font-weight: 700;
      vertical-align: middle;
    }
    .delivery-items-table th {
      background: #d1d5db;
      text-align: center;
      line-height: 1.25;
    }
    .delivery-items-table th .th-en {
      display: block;
      font-size: 11px;
      font-weight: 600;
    }
    .delivery-items-table .no,
    .delivery-items-table .qty {
      text-align: center;
    }
    .delivery-items-table .product {
      text-align: left;
    }
    .delivery-items-table .empty { text-align: center; }
    .delivery-items-table tfoot td {
      background: #fff;
      font-weight: 800;
    }
    .delivery-items-table .qty-total-label {
      text-align: right;
      font-size: 12px;
      line-height: 1.3;
    }
    .delivery-items-table .qty-total-value {
      text-align: center;
      font-size: 15px;
      font-weight: 900;
    }
    .delivery-info-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin: 0.2rem 0 0.35rem;
      padding: 5px 7px;
      border: 1px solid #111;
      border-radius: 8px;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
    }
    .delivery-info-line .delivery-label {
      color: #111;
      font-size: 13px;
      font-weight: 800;
      line-height: 1.25;
    }
    .delivery-info-line .delivery-value {
      min-width: 72px;
      border-radius: 999px;
      background: #111;
      color: #fff;
      padding: 3px 10px;
      font-size: 13px;
      font-weight: 900;
      line-height: 1.25;
      text-align: center;
    }
    .receipt-powered-by {
      margin-top: 0.65rem;
      color: #111;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-align: center;
    }
    .thermal-cut-feed { display: none; }
    @page { margin: 0; }
    @media print {
      html, body { background: #fff; }
      .receipt-card {
        box-shadow: none;
        border: none;
        margin: 0;
        max-width: 100%;
        page-break-after: always;
        break-after: page;
      }
      .receipt-card:last-child {
        page-break-after: auto;
        break-after: auto;
      }
      .thermal-cut-feed {
        display: block;
        height: 18mm;
      }
    }
  </style>
</head>
<body>
  <div class="invoice receipt-card">
    <div class="receipt-header-logo">${m?`<img id="delivery-slip-logo" src="${l(m)}" alt="Logo">`:""}</div>
    <div class="receipt-title">&#x1794;&#x178E;&#x17D2;&#x178E;&#x178A;&#x17B9;&#x1780; / Delivery Slip</div>
    <div class="top-line">
      <div>
        <div><span class="label-col">Created By:</span> <span class="value-col">${l(n.created_by_name||"-")}</span></div>
        <div class="code">Code: ${l(d)}</div>
      </div>
      <img src="${l(g)}" alt="QR" class="receipt-qr">
    </div>
    <div class="section-divider"></div>
    <div class="section-title">Customer</div>
    <div class="info-row"><span class="label-col">Name</span><span class="value-col">${l(n.event_name||"-")}</span></div>
    <div class="info-row"><span class="label-col">Phone</span><span class="value-col">${l(n.phone||"-")}</span></div>
    <div class="info-row"><span class="label-col">&#x1791;&#x17B8;&#x178F;&#x17B6;&#x17C6;&#x1784;</span><span class="value-col">${l(n.location||"-")}</span></div>
    <div class="section-divider"></div>
    <table class="delivery-items-table">
      <thead>
        <tr>
          <th style="width:12%;">&#x179B;.&#x179A;<span class="th-en">No</span></th>
          <th style="width:58%;">&#x1798;&#x17BB;&#x1781;&#x1791;&#x17C6;&#x1793;&#x17B7;&#x1789;<span class="th-en">Product Name</span></th>
          <th style="width:30%;">&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;<span class="th-en">Qty</span></th>
        </tr>
      </thead>
      <tbody>${r}</tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="qty-total-label">&#x179F;&#x179A;&#x17BB;&#x1794;&#x1785;&#x17C6;&#x1793;&#x17BD;&#x1793;</td>
          <td class="qty-total-value">${l(o(i.taken))}</td>
        </tr>
      </tfoot>
    </table>
    <div class="section-divider"></div>
    <div class="section-title">Delivery</div>
    <div class="delivery-info-line">
      <span class="delivery-label">&#x178A;&#x17B9;&#x1780;&#x178A;&#x17C4;&#x1799;</span>
      <span class="delivery-value">${l(n.delivery_type_name||"Marketing")}</span>
    </div>
    <div class="info-row"><span class="label-col">Type</span><span class="value-col">${l(n.marketing_type_label||T(n.marketing_type))}</span></div>
    <div class="info-row"><span class="label-col">Created</span><span class="value-col">${l(n.created_at||"-")}</span></div>
    <div class="receipt-powered-by">Powered by : One Night Solution</div>
    <div class="thermal-cut-feed" aria-hidden="true"></div>
  </div>
</body>
</html>`}function Ie(e){J(le(e||{}),"delivery-slip-logo")}async function oe(e){const n=e?.take?.id||e?.id;return n?e?.take&&Array.isArray(e?.items)?e:Oe(n):null}async function ot(e){const n=await oe(e);n&&Ae(n)}function Fe(e){const n=e.indexOf('<div class="invoice receipt-card">'),s=e.lastIndexOf("</body>");return n<0?"":e.slice(n,s===-1?e.length:s).trim()}function Ke(e){const n=e.map(a=>le(a)),s=n.slice(1).map(Fe).join(`
`);return n[0].replace("</style>",".invoice { page-break-after: always; } .invoice:last-of-type { page-break-after: auto; }</style>").replace("</body>",`${s}</body>`)}async function Ue(e){const n=(e||[]).filter(L);if(!n.length)throw new Error("Cannot print delivery note while the request is pending approval.");const s=[];for(const a of n){const i=await oe(a);i&&Y(i)||i&&s.push(i)}if(!s.length)throw new Error("Cannot print delivery note while the request is pending approval.");if(s.length===1){Ie(s[0]);return}J(Ke(s))}async function Oe(e){return G("/api/reports/marketing.php",{action:"detail",id:e})}function dt({children:e,onExcel:n,onPrint:s,onDeliveryNote:a,selectedCount:i=0,printing:r=!1,disabled:m}){return t.jsxs("div",{className:"marketing-report-actions",children:[t.jsxs("button",{type:"button",onClick:n,disabled:m,children:[t.jsx(pe,{size:15})," ",t.jsx("span",{children:"Excel"})]}),t.jsxs("button",{type:"button",onClick:s,disabled:m,children:[t.jsx(ue,{size:15})," ",t.jsx("span",{children:"Print"})]}),a?t.jsxs("button",{type:"button",onClick:a,disabled:r||i<1,children:[r?t.jsx(O,{className:"spin",size:15}):t.jsx(ee,{size:15}),t.jsxs("span",{children:["Delivery Note",i?` (${i})`:""]})]}):null,e]})}function ct({row:e,onDetail:n,onInvoice:s,onEdit:a}){return t.jsxs("div",{className:"marketing-row-actions is-icons",children:[t.jsx("button",{type:"button",title:"View","aria-label":"View",onClick:()=>n(e),children:t.jsx(ye,{size:14})}),a?t.jsx("button",{type:"button",title:"Edit","aria-label":"Edit",onClick:()=>a(e),children:t.jsx(be,{size:14})}):null,s&&["pending","completed"].includes(e.status)?t.jsx("button",{type:"button",title:"Invoice","aria-label":"Invoice",onClick:()=>s(e),children:t.jsx(ee,{size:14})}):null]})}export{ke as C,ct as D,Je as M,dt as R,nt as a,Ze as b,lt as c,rt as d,et as e,at as f,st as g,it as h,ot as i,We as j,je as k,Oe as l,tt as p,Qe as q,Xe as u};
