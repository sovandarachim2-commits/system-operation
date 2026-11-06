import{r as C,a as G,j as t,F as me,c as pe,J as O,y as ee,e as ue,b as he,C as ge,E as xe,Z as ye}from"./index-BJCepwD8.js";import{r as fe}from"./index-BHcwB_8Z.js";import{P as ve}from"./pencil-CdWLNGcc.js";import{C as be}from"./circle-x-Dd2UBdSX.js";const te=[["pending_approval","Pending Approval"],["pending","In Marketing"],["completed","Completed"],["rejected","Rejected"]];function ne(e){return String(e?.take?.status||e?.status||"")}function B(e){return["pending","completed"].includes(ne(e))}function Y(e){const n=ne(e);return n==="pending_approval"?"Cannot print delivery note while the request is pending approval.":n==="rejected"?"Cannot print delivery note for a rejected request.":B(e)?"":"Cannot print delivery note for this request."}function je({children:e}){return C.useEffect(()=>{const n=document.body.style.overflow;return document.body.style.overflow="hidden",()=>{document.body.style.overflow=n}},[]),fe.createPortal(e,document.body)}function Z(e){return String(e).padStart(2,"0")}function I(e=new Date){return`${e.getFullYear()}-${Z(e.getMonth()+1)}-${Z(e.getDate())}`}const _e=()=>I(new Date);function ae(e=new Date){const n=new Date(e.getFullYear(),e.getMonth(),1),s=new Date(e.getFullYear(),e.getMonth()+1,0);return{from:I(n),to:I(s)}}function ke(e=new Date){const n=new Date(e.getFullYear(),e.getMonth()-1,1),s=new Date(e.getFullYear(),e.getMonth(),0);return{from:I(n),to:I(s)}}function d(e){const n=Number(e||0);return Number.isFinite(n)?Math.round(n).toLocaleString():"0"}function He(e){if(e===""||e==null)return"";const n=Number(e);return Number.isFinite(n)?String(Math.round(n)):""}function K(e){if(!e)return"-";const n=String(e).trim(),s=n.match(/^(\d{4}-\d{2}-\d{2})/);if(s){const[i,r,p]=s[1].split("-").map(Number);return new Date(i,r-1,p).toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}const a=new Date(n.replace(" ","T"));return Number.isNaN(a.getTime())?n:a.toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}function Ne(e){const n=String(e||"").trim().match(/^(\d{4}-\d{2}-\d{2})/);return n?n[1]:""}function $e(e,n,s){return!n||!s?e:(e||[]).filter(a=>{const i=Ne(a.event_date);return i&&i>=n&&i<=s})}function we(e,n){return{action:"report",mode:e,include_items:1,date_from:n.from,date_to:n.to,status:n.status,marketing_type:n.marketing_type,product_id:n.product_id,created_by:n.created_by,delivery_by:n.delivery_by,q:n.q}}function Se(e){if(!e)return"-";const n=new Date(String(e).replace(" ","T"));return Number.isNaN(n.getTime())?e:n.toLocaleDateString(void 0,{month:"short",day:"numeric",year:"numeric"})}function Te(e){if(!e)return"-";const n=new Date(String(e).replace(" ","T"));return Number.isNaN(n.getTime())?e:n.toLocaleDateString("km-KH",{day:"numeric",month:"long",year:"numeric"})}function se(e){return e==="completed"?"success":e==="rejected"?"danger":e==="pending"?"info":"warning"}function l(e){return String(e??"").replace(/[&<>"']/g,n=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[n])}function F(e){return e==null||e===""?"-":e}const U=[["ugc_sample","UGC Sample"],["influencer_kol","Influencer / KOL"],["event","Event"],["giveaway","Giveaway"],["promotion","Promotion"],["sponsorship","Sponsorship"],["internal_use","Internal Use"],["other","Other"]];function q(e,n=U){const a=n.map(i=>Array.isArray(i)?i:[i.value,i.label]).find(([i])=>i===e);return a?a[1]:F(e)}function Qe(){return{event_name:"",marketing_type:"",delivery_type_id:"",event_date:_e(),location:"",phone:"",notes:"",scan_out_invs:"",existingImages:[],imageFiles:[],items:[{product_id:"",quantity:""}]}}const z=[["No.",(e,n)=>n+1],["Code",e=>e.display_code],["Event",e=>e.event_name],["Type",e=>e.marketing_type_label||q(e.marketing_type)],["Location",e=>e.location],["Phone",e=>e.phone],["Marketing Date",e=>K(e.event_date)],["Delivery By",e=>e.delivery_by||"-"],["Status",e=>e.status_label],["Items",e=>d(e.item_count)],["Taken",e=>d(e.total_taken)],["Returned",e=>d(e.total_returned)],["Created By",e=>e.created_by_name]],qe=[["No.",(e,n)=>n+1],["Product",e=>e.product_name],["SKU",e=>e.sku],["Events",e=>d(e.event_count)],["Pending",e=>d(e.pending_qty)],["Taken",e=>d(e.total_taken)],["Returned",e=>d(e.total_returned)],["Not Returned",e=>d(e.total_not_returned)],["Remaining",e=>d(e.remaining_qty)],["Status",e=>e.status_label]];function V(e){return String(e||"").split(",").map(n=>n.trim()).filter(Boolean)}function We(e="all"){const[n,s]=C.useState(()=>{const b=ae();return{from:b.from,to:b.to,status:"",marketing_type:"",product_id:"",created_by:"",delivery_by:"",q:""}}),[a,i]=C.useState({products:[],creators:[],locations:[],delivery_types:[],delivery_by:[],marketing_types:U.map(([b,S])=>({value:b,label:S}))}),[r,p]=C.useState([]),[c,y]=C.useState([]),[g,x]=C.useState({}),[v,u]=C.useState({}),[f,m]=C.useState(!0),[w,h]=C.useState(""),[j,$]=C.useState(""),k=C.useRef(0);async function _(b=n){const S=++k.current;m(!0),h("");try{const[T,L]=await Promise.all([G("/api/reports/marketing.php",{action:"options"}),G("/api/reports/marketing.php",we(e,b))]);if(S!==k.current)return;i({products:T.products||[],creators:T.creators||[],locations:T.locations||[],delivery_types:T.delivery_types||[],delivery_by:T.delivery_by||[],marketing_types:T.marketing_types?.length?T.marketing_types:U.map(([de,ce])=>({value:de,label:ce}))}),p($e(L.rows||[],b.from,b.to)),y(L.products||[]),x(L.counts||{}),u(L.type_counts||{})}catch(T){if(S!==k.current)return;h(T.message||"Unable to load marketing data.")}finally{S===k.current&&m(!1)}}C.useEffect(()=>{const b=window.setTimeout(()=>_(n),180);return()=>window.clearTimeout(b)},[e,n.from,n.to,n.status,n.marketing_type,n.product_id,n.created_by,n.delivery_by,n.q]);function o(b,S){s(T=>({...T,[b]:S}))}function N(b,S){s(T=>({...T,from:b,to:S}))}function R(b){o("status",n.status===b?"":b)}function E(b){const S=V(n.marketing_type),T=S.includes(b)?S.filter(L=>L!==b):[...S,b];o("marketing_type",T.join(","))}const P=V(n.marketing_type).map(b=>q(b,a.marketing_types)).filter(Boolean),A=n.product_id?a.products.find(b=>String(b.value)===String(n.product_id))?.label:"",M=`${n.from||"-"} to ${n.to||"-"}${n.status?` | ${te.find(([b])=>b===n.status)?.[1]||n.status}`:""}${P.length?` | ${P.join(", ")}`:""}${A?` | Product: ${A}`:""}`;return{filters:n,setFilter:o,applyRange:N,toggleStatus:R,toggleType:E,options:a,rows:r,products:c,counts:g,typeCounts:v,loading:f,error:w,setError:h,notice:j,setNotice:$,load:_,subtitle:M}}function Je({notice:e,error:n}){return t.jsxs(t.Fragment,{children:[e?t.jsx("div",{className:"marketing-report-alert success",children:e}):null,n?t.jsx("div",{className:"marketing-report-alert",children:n}):null]})}function Xe({counts:e,active:n,onToggle:s}){const a=[["pending_approval","Pending Approval",e.pending_approval||0],["pending","In Marketing",e.pending||0],["completed","Completed",e.completed||0]];return t.jsx("section",{className:"marketing-report-stats","aria-label":"Marketing summary",children:a.map(([i,r,p])=>t.jsxs("button",{type:"button",className:n===i?"active":"",onClick:()=>s(i),children:[t.jsx("span",{children:r}),t.jsx("strong",{children:d(p)})]},i))})}function Ze({filters:e,setFilter:n,applyRange:s,onRefresh:a,loading:i,showStatus:r=!0,showProductFilters:p=!1,marketingTypes:c=U,productOptions:y=[],creatorOptions:g=[],deliveryByOptions:x=[],typeCounts:v={},onToggleType:u}){const f=ae(),m=ke(),w=e.from===f.from&&e.to===f.to?"current":e.from===m.from&&e.to===m.to?"last":"",h=V(e.marketing_type),j=Object.values(v||{}).reduce((o,N)=>o+Number(N||0),0),$=h.length?h.length===1?`${q(h[0],c)} (${d(v?.[h[0]]||0)})`:`${h.length} types`:`All types (${d(j)})`;function k(o){s?s(o.from,o.to):(n("from",o.from),n("to",o.to))}function _(o){if(u){u(o);return}const N=h.includes(o)?h.filter(R=>R!==o):[...h,o];n("marketing_type",N.join(","))}return t.jsxs("div",{className:"marketing-report-filters",children:[t.jsxs("div",{className:"marketing-date-presets",children:[t.jsx("span",{children:"Marketing Date"}),t.jsxs("div",{children:[t.jsx("button",{type:"button",className:w==="current"?"active":"",onClick:()=>k(f),children:"This month"}),t.jsx("button",{type:"button",className:w==="last"?"active":"",onClick:()=>k(m),children:"Last month"})]})]}),t.jsxs("div",{className:"marketing-date-range",children:[t.jsxs("label",{className:"marketing-date-field",children:[t.jsx("span",{children:"From"}),t.jsx("input",{type:"date",value:e.from,onChange:o=>n("from",o.target.value)})]}),t.jsxs("label",{className:"marketing-date-field",children:[t.jsx("span",{children:"To"}),t.jsx("input",{type:"date",value:e.to,onChange:o=>n("to",o.target.value)})]})]}),r?t.jsxs("label",{className:"marketing-status-field",children:[t.jsx("span",{children:"Status"}),t.jsxs("select",{value:e.status,onChange:o=>n("status",o.target.value),children:[t.jsx("option",{value:"",children:"All status"}),te.map(([o,N])=>t.jsx("option",{value:o,children:N},o))]})]}):null,t.jsxs("div",{className:"marketing-type-field",children:[t.jsx("span",{children:"Type"}),t.jsxs("details",{className:"marketing-checkbox-filter",children:[t.jsx("summary",{children:$}),t.jsxs("div",{className:"marketing-checkbox-filter-menu",role:"group","aria-label":"Filter by marketing type",children:[t.jsxs("label",{className:h.length?"":"active",children:[t.jsx("input",{type:"checkbox",checked:!h.length,onChange:()=>n("marketing_type","")}),t.jsx("em",{children:"All types"}),t.jsx("strong",{children:d(j)})]}),c.map(o=>{const[N,R]=Array.isArray(o)?o:[o.value,o.label],E=Array.isArray(o)?"":o.image_url||"",P=h.includes(N);return t.jsxs("label",{className:P?"active":"",children:[t.jsx("input",{type:"checkbox",checked:P,onChange:()=>_(N)}),E?t.jsx("img",{src:E,alt:"",className:"marketing-type-thumb"}):null,t.jsx("em",{children:R}),t.jsx("strong",{children:d(v?.[N]||0)})]},N)})]})]})]}),p?t.jsxs("label",{className:"marketing-status-field",children:[t.jsx("span",{children:"By product"}),t.jsxs("select",{value:e.product_id||"",onChange:o=>n("product_id",o.target.value),children:[t.jsx("option",{value:"",children:"All products"}),(y||[]).map(o=>t.jsx("option",{value:o.value,children:o.label},o.value))]})]}):null,t.jsxs("label",{className:"marketing-status-field",children:[t.jsx("span",{children:"Delivery By"}),t.jsxs("select",{value:e.delivery_by||"",onChange:o=>n("delivery_by",o.target.value),children:[t.jsx("option",{value:"",children:"All delivery"}),x.map(o=>t.jsx("option",{value:o.value,children:o.label},o.value))]})]}),t.jsxs("div",{className:"marketing-search-row",children:[t.jsxs("label",{className:"marketing-search",children:[t.jsx("span",{children:"Search"}),t.jsxs("div",{children:[t.jsx(ue,{size:15}),t.jsx("input",{value:e.q,onChange:o=>n("q",o.target.value),placeholder:"Code, event, creator..."})]})]}),t.jsxs("button",{type:"button",className:"marketing-refresh-btn",onClick:()=>a(),disabled:i,"aria-label":"Refresh",children:[i?t.jsx(O,{className:"spin",size:16}):t.jsx(he,{size:16})," ",t.jsx("span",{children:"Refresh"})]})]})]})}function D(e,n){return e.reduce((s,a)=>s+Number(a[n]||0),0)}function H(e=[]){return{items:D(e,"item_count"),taken:D(e,"total_taken"),returned:D(e,"total_returned")}}function ie(e){return e==="pending"||e==="completed"}function Q(e=[],{includePending:n=!0}={}){return{events:D(e,"event_count"),pending:n?D(e,"pending_qty"):0,taken:D(e,"total_taken"),returned:D(e,"total_returned"),notReturned:D(e,"total_not_returned"),remaining:D(e,"remaining_qty")}}function W(e){return e.status_label?e.status_label:Number(e.pending_qty||0)>0?"Pending":Number(e.remaining_qty||0)>0?"In Marketing":"Done"}function Ce(e){return e==="done"?"success":e==="pending"?"warning":"info"}function Re(e){const n=e.types||e.by_type||e.type_breakdown||e.marketing_types;return Array.isArray(n)&&n.length?n.map(s=>typeof s=="object"?s:{marketing_type:s}):n&&typeof n=="object"?Object.entries(n).map(([s,a])=>typeof a=="object"&&a?{marketing_type:s,...a}:{marketing_type:s,total_taken:a}):[]}function Me(e=[],n=[]){const s=[];return e.forEach(a=>{const i=Re(a);if(i.length){i.forEach(r=>{const p=r.marketing_type||r.type||a.marketing_type||"other";s.push({...a,...r,marketing_type:p,marketing_type_label:r.marketing_type_label||r.type_label||q(p,n)})});return}(a.marketing_type||a.marketing_type_label)&&s.push(a)}),s}function De(e=[],n=[]){const s=Me(e,n);if(!s.length)return null;const a=new Map;return s.forEach(i=>{const r=String(i.marketing_type||"other");a.has(r)||a.set(r,{key:r,label:i.marketing_type_label||q(r,n),rows:[]}),a.get(r).rows.push(i)}),Array.from(a.values())}function Pe(e){return e?.items||e?.products||e?.take_items||[]}function Ee(e=[],n=[]){const s=new Map;return e.forEach(a=>{if(a.status==="rejected")return;const i=Pe(a);if(!i.length)return;const r=String(a.marketing_type||"other"),p=a.marketing_type_label||q(r,n);s.has(r)||s.set(r,{key:r,label:p,products:new Map});const c=s.get(r).products,y=a.status==="pending_approval",g=ie(a.status);i.forEach(x=>{const v=String(x.product_id||x.id||x.product_name||"");if(!v)return;const u=Number(x.quantity_taken||x.quantity||0),f=Number(x.quantity_returned||0),m=Number(x.quantity_not_returned||0),w=Number(x.remaining_qty||Math.max(0,u-f-m)),h=c.get(v)||{product_id:v,product_name:x.product_name||"-",sku:x.sku||"",event_count:0,pending_qty:0,total_taken:0,total_returned:0,total_not_returned:0,remaining_qty:0,marketing_type:r,marketing_type_label:p};h.event_count+=1,y?h.pending_qty+=u:g&&(h.total_taken+=u,h.total_returned+=f,h.total_not_returned+=m,h.remaining_qty+=w),c.set(v,h)})}),s.size?Array.from(s.values()).map(a=>({key:a.key,label:a.label,rows:Array.from(a.products.values()).map(i=>({...i,status:i.pending_qty>0?"pending":i.remaining_qty>0?"open":"done",status_label:W(i)}))})):null}function Le(e=[],n=[]){const s=new Map;return e.forEach(a=>{if(a.status==="rejected")return;const i=String(a.marketing_type||"other"),r=s.get(i)||{marketing_type:i,product_id:`type-${i}`,product_name:a.marketing_type_label||q(i,n),sku:"",event_count:0,pending_qty:0,total_taken:0,total_returned:0,total_not_returned:0,remaining_qty:0,item_count:0},p=Number(a.total_taken||0),c=Number(a.total_returned||0),y=Number(a.total_not_returned||0),g=Number(a.remaining_qty||Math.max(0,p-c-y));r.event_count+=1,r.item_count+=Number(a.item_count||0),a.status==="pending_approval"?r.pending_qty+=p:ie(a.status)&&(r.total_taken+=p,r.total_returned+=c,r.total_not_returned+=y,r.remaining_qty+=g),s.set(i,r)}),Array.from(s.values()).map(a=>{const i=a.remaining_qty||Math.max(0,a.total_taken-a.total_returned-a.total_not_returned);return{...a,remaining_qty:i,status:a.pending_qty>0?"pending":i>0?"open":"done",status_label:W({...a,remaining_qty:i})}})}function et(e,n=[],s=[],a=[]){if(e!=="type")return{rows:n,groups:null,nameLabel:"Product",showTypeColumn:!1};const i=De(n,a),r=i?null:Ee(s,a),p=i||r;return p?{rows:p.flatMap(c=>c.rows),groups:null,nameLabel:"Product",showTypeColumn:!0}:{rows:Le(s,a),groups:null,nameLabel:"Type",showTypeColumn:!1}}function tt(e=[],n){const[s,a]=C.useState([]),[i,r]=C.useState(!1),p=e.filter(B),c=p.map(h=>String(h.id)),y=new Set(s),g=c.filter(h=>y.has(h)),x=p.filter(h=>y.has(String(h.id))),v=c.length>0&&g.length===c.length,u=g.length>0&&!v;function f(h,j,$){const k=String(h),_=$||e.find(o=>String(o.id)===k);if(j&&_&&!B(_)){n?.(Y(_));return}a(o=>{const N=o.includes(k);return j&&!N?[...o,k]:!j&&N?o.filter(R=>R!==k):o})}function m(h){a(h?c:[])}async function w(){if(!x.length){n?.("Select at least one approved request to print a delivery note.");return}n?.(""),r(!0);try{await Ke(x)}catch(h){n?.(h.message||"Unable to print delivery note.")}finally{r(!1)}}return{selectedIds:g,selectedRows:x,allSelected:v,someSelected:u,printing:i,toggleSelected:f,toggleAll:m,printSelectedDeliveryNotes:w}}function nt({rows:e,loading:n,emptyText:s="Try changing the filter.",renderActions:a,selectedIds:i,allSelected:r,someSelected:p,onToggleSelected:c,onToggleAll:y,onViewImages:g}){if(n)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(O,{className:"spin",size:26}),t.jsx("strong",{children:"Loading marketing data..."})]});if(!e.length)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(ge,{size:28}),t.jsx("strong",{children:"No marketing requests found."}),t.jsx("span",{children:s})]});const x=H(e),v=typeof c=="function",u=new Set((i||[]).map(String)),f=e.filter(B).length;return t.jsx("div",{className:"marketing-table-wrap",children:t.jsxs("table",{className:"marketing-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[v?t.jsx("th",{className:"col-check",children:t.jsx("input",{type:"checkbox",checked:!!r,disabled:f<1,ref:m=>{m&&(m.indeterminate=!!p)},onChange:()=>y?.(!r),"aria-label":"Select all approved requests",title:f<1?"Pending requests cannot print a delivery note.":"Select all approved requests"})}):null,t.jsx("th",{className:"hide-sm",children:"No."}),t.jsx("th",{children:"Code"}),t.jsx("th",{children:"Event"}),t.jsx("th",{className:"hide-sm",children:"Type"}),t.jsx("th",{children:"Marketing Date"}),t.jsx("th",{children:"Delivery By"}),t.jsx("th",{children:"Status"}),t.jsx("th",{className:"num hide-md",children:"Items"}),t.jsx("th",{className:"num hide-md",children:"Taken"}),t.jsx("th",{className:"num hide-sm",children:"Returned"}),t.jsx("th",{className:"hide-sm",children:"Created By"}),t.jsx("th",{className:"col-image",children:"Image"}),t.jsx("th",{children:"Action"})]})}),t.jsx("tbody",{children:e.map((m,w)=>{const h=(m.image_urls||[]).length,j=B(m),$=j?"":Y(m);return t.jsxs("tr",{className:u.has(String(m.id))?"is-selected":void 0,children:[v?t.jsx("td",{className:"col-check",children:t.jsx("label",{className:`marketing-check-cell${j?"":" is-disabled"}`,title:$||"Select to print delivery note",children:t.jsx("input",{type:"checkbox",checked:u.has(String(m.id)),disabled:!j,onChange:k=>c(m.id,k.target.checked,m),"aria-label":`Select ${m.display_code||m.event_name||m.id}`})})}):null,t.jsx("td",{className:"col-no hide-sm",children:w+1}),t.jsx("td",{children:t.jsx("code",{children:m.display_code})}),t.jsxs("td",{children:[t.jsx("strong",{children:m.event_name}),t.jsx("span",{children:m.location||"-"}),t.jsx("span",{className:"show-sm marketing-row-extra",children:m.marketing_type_label||q(m.marketing_type)})]}),t.jsx("td",{className:"hide-sm",children:m.marketing_type_label||q(m.marketing_type)}),t.jsx("td",{children:K(m.event_date)}),t.jsx("td",{children:m.delivery_by||"-"}),t.jsx("td",{children:t.jsx("span",{className:`marketing-status ${se(m.status)}`,children:m.status_label})}),t.jsx("td",{className:"num hide-md",children:d(m.item_count)}),t.jsx("td",{className:"num is-taken hide-md",children:d(m.total_taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:d(m.total_returned)}),t.jsx("td",{className:"hide-sm",children:m.created_by_name||"-"}),t.jsx("td",{className:"marketing-image-cell",children:h?t.jsxs("button",{type:"button",className:"marketing-image-view",title:"View image","aria-label":"View image",onClick:()=>g?.(m),children:[t.jsx("img",{src:m.image_url||m.image_urls?.[0],alt:""}),t.jsx("span",{children:h})]}):t.jsx("span",{className:"marketing-no-image",children:"-"})}),t.jsx("td",{className:"marketing-action-cell",children:a?.(m)})]},m.id)})}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[v?t.jsx("td",{className:"col-check"}):null,t.jsx("td",{className:"hide-sm"}),t.jsx("td",{className:"marketing-total-label",children:"Total"}),t.jsx("td",{}),t.jsx("td",{}),t.jsx("td",{className:"hide-sm"}),t.jsx("td",{}),t.jsx("td",{}),t.jsx("td",{className:"num hide-md",children:d(x.items)}),t.jsx("td",{className:"num is-taken hide-md",children:d(x.taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:d(x.returned)}),t.jsx("td",{className:"hide-sm"}),t.jsx("td",{}),t.jsx("td",{})]})})]})})}function at({rows:e,loading:n,nameLabel:s="Product",showTypeColumn:a=!1,emptyText:i="Try changing the date or status filter."}){if(n)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(O,{className:"spin",size:26}),t.jsx("strong",{children:"Loading product report..."})]});if(!e.length)return t.jsxs("div",{className:"marketing-report-empty",children:[t.jsx(ye,{size:28}),t.jsxs("strong",{children:["No ",s==="Type"?"marketing types":"products"," found."]}),t.jsx("span",{children:i})]});const r=Q(e,{includePending:!a}),p=s==="Type";return t.jsx("div",{className:"marketing-table-wrap",children:t.jsxs("table",{className:"marketing-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[t.jsx("th",{className:"hide-sm",children:"No."}),t.jsx("th",{children:s}),p?null:t.jsx("th",{className:"hide-sm",children:"SKU"}),a?t.jsx("th",{children:"Marketing Type"}):null,t.jsx("th",{className:"num hide-md",children:"Events"}),t.jsx("th",{className:"num hide-md",children:"Pending"}),t.jsx("th",{className:"num",children:"Taken"}),t.jsx("th",{className:"num hide-sm",children:"Returned"}),t.jsx("th",{className:"num hide-sm",children:"Not Returned"}),t.jsx("th",{className:"num",children:"Remaining"}),t.jsx("th",{children:"Status"})]})}),t.jsx("tbody",{children:e.map((c,y)=>t.jsxs("tr",{children:[t.jsx("td",{className:"col-no hide-sm",children:y+1}),t.jsxs("td",{children:[t.jsx("strong",{children:c.product_name||"-"}),p?null:t.jsx("span",{className:"show-sm marketing-row-extra",children:c.sku||"-"})]}),p?null:t.jsx("td",{className:"hide-sm",children:c.sku||"-"}),a?t.jsx("td",{children:c.marketing_type_label||q(c.marketing_type)||"-"}):null,t.jsx("td",{className:"num hide-md",children:d(c.event_count)}),t.jsx("td",{className:"num is-pending hide-md",children:d(c.pending_qty)}),t.jsx("td",{className:"num is-taken",children:d(c.total_taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:d(c.total_returned)}),t.jsx("td",{className:"num hide-sm",children:d(c.total_not_returned)}),t.jsx("td",{className:"num is-remaining",children:d(c.remaining_qty)}),t.jsx("td",{children:t.jsx("span",{className:`marketing-status ${Ce(c.status)}`,children:c.status_label||W(c)})})]},`${c.marketing_type||"all"}-${c.product_id||y}`))}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[t.jsx("td",{className:"hide-sm"}),t.jsx("td",{className:"marketing-total-label",children:"Total"}),p?null:t.jsx("td",{className:"hide-sm"}),a?t.jsx("td",{}):null,t.jsx("td",{className:"num hide-md",children:d(r.events)}),t.jsx("td",{className:"num is-pending hide-md",children:a?"-":d(r.pending)}),t.jsx("td",{className:"num is-taken",children:d(r.taken)}),t.jsx("td",{className:"num is-returned hide-sm",children:d(r.returned)}),t.jsx("td",{className:"num hide-sm",children:d(r.notReturned)}),t.jsx("td",{className:"num is-remaining",children:d(r.remaining)}),t.jsx("td",{})]})})]})})}function J(e=[]){return e.reduce((n,s)=>({taken:n.taken+Number(s.quantity_taken||0),returned:n.returned+Number(s.quantity_returned||0),notReturned:n.notReturned+Number(s.quantity_not_returned||0),remaining:n.remaining+Number(s.remaining_qty||0)}),{taken:0,returned:0,notReturned:0,remaining:0})}function st({take:e,items:n,extraColumns:s,extraCells:a,extraFooter:i,children:r,footer:p,onClose:c,className:y=""}){const g=J(n),x=(e.image_urls||[]).filter(Boolean),v=[["Code",e.display_code||"-"],["Date",K(e.event_date)],["Type",e.marketing_type_label||q(e.marketing_type)],["Delivery",e.delivery_type_name||"-"],["Location",e.location||"-"],["Phone",e.phone||"-"],["Created By",e.created_by_name||"-"]];return t.jsx(je,{children:t.jsx("div",{className:"marketing-modal-backdrop",role:"presentation",onMouseDown:c,children:t.jsxs("section",{className:"marketing-modal",role:"dialog","aria-modal":"true","aria-label":"Marketing request detail",onMouseDown:u=>u.stopPropagation(),children:[t.jsxs("header",{className:"marketing-detail-header",children:[t.jsxs("div",{children:[t.jsx("code",{className:"marketing-detail-code",children:e.display_code||"-"}),t.jsxs("div",{className:"marketing-modal-title",children:[t.jsx("h3",{children:e.event_name||"Marketing request"}),t.jsx("span",{className:`marketing-status ${se(e.status)}`,children:e.status_label})]}),t.jsxs("p",{className:"marketing-modal-meta",children:[t.jsx("code",{children:e.display_code}),t.jsx("span",{children:K(e.event_date)}),t.jsx("span",{children:e.marketing_type_label||q(e.marketing_type)}),e.delivery_type_name?t.jsx("span",{children:e.delivery_type_name}):null,t.jsx("span",{children:e.location||"—"}),e.phone?t.jsx("span",{children:e.phone}):null,t.jsx("span",{children:e.created_by_name||"—"})]})]}),t.jsx("button",{type:"button",onClick:c,"aria-label":"Close",children:t.jsx(be,{size:16})})]}),t.jsxs("div",{className:"marketing-modal-body",children:[t.jsx("section",{className:"marketing-detail-summary","aria-label":"Marketing request summary",children:v.map(([u,f])=>t.jsxs("div",{className:"marketing-detail-field",children:[t.jsx("span",{children:u}),t.jsx("strong",{children:f})]},u))}),x.length?t.jsxs("section",{className:"marketing-detail-section",children:[t.jsxs("div",{className:"marketing-detail-section-title",children:[t.jsx("strong",{children:"Images"}),t.jsx("span",{children:x.length})]}),t.jsx("div",{className:"marketing-detail-images",children:x.map((u,f)=>t.jsx("a",{href:u,target:"_blank",rel:"noreferrer","aria-label":`Open image ${f+1}`,children:t.jsx("img",{src:u,alt:""})},u))})]}):null,e.notes?t.jsxs("section",{className:"marketing-detail-section",children:[t.jsx("div",{className:"marketing-detail-section-title",children:t.jsx("strong",{children:"Note"})}),t.jsx("p",{className:"marketing-modal-note",children:e.notes})]}):null,t.jsxs("section",{className:"marketing-detail-section",children:[t.jsxs("div",{className:"marketing-detail-section-title",children:[t.jsx("strong",{children:"Products"}),t.jsx("span",{children:n.length})]}),t.jsx("div",{className:"marketing-table-wrap modal-table",children:t.jsxs("table",{className:"marketing-table marketing-detail-table",children:[t.jsx("thead",{children:t.jsxs("tr",{children:[t.jsx("th",{children:"Product"}),t.jsx("th",{className:"num",children:"Taken"}),t.jsx("th",{className:"num",children:"Returned"}),t.jsx("th",{className:"num",children:"Not Returned"}),t.jsx("th",{className:"num",children:"Remaining"}),s]})}),t.jsx("tbody",{children:n.map(u=>t.jsxs("tr",{children:[t.jsxs("td",{children:[t.jsx("strong",{children:u.product_name}),u.sku?t.jsx("span",{children:u.sku}):null]}),t.jsx("td",{className:"num",children:d(u.quantity_taken)}),t.jsx("td",{className:"num",children:d(u.quantity_returned)}),t.jsx("td",{className:"num",children:d(u.quantity_not_returned)}),t.jsx("td",{className:"num",children:d(u.remaining_qty)}),a?.(u)]},u.id))}),t.jsx("tfoot",{children:t.jsxs("tr",{children:[t.jsx("td",{children:"Total"}),t.jsx("td",{className:"num",children:d(g.taken)}),t.jsx("td",{className:"num",children:d(g.returned)}),t.jsx("td",{className:"num",children:d(g.notReturned)}),t.jsx("td",{className:"num",children:d(g.remaining)}),i]})})]})})]})]}),r,p]})})})}function re(e="Product",n=!1){const s=qe.map((a,i)=>i===1?[e,a[1]]:a);return n?[...s.slice(0,3),["Marketing Type",a=>a.marketing_type_label||q(a.marketing_type)],...s.slice(3)]:s}function it(e,n,s,a=[],i={}){const r=new Date().toLocaleString(),p=z.length,c=s.map((o,N)=>`<tr>${z.map(([,R])=>`<td>${l(F(R(o,N)))}</td>`).join("")}</tr>`).join(""),y=a,g=i.productColumns||re(i.productNameLabel,i.showTypeColumn),x=g.length,v=y.map((o,N)=>`<tr>${g.map(([,R])=>`<td>${l(F(R(o,N)))}</td>`).join("")}</tr>`).join(""),u=H(s),f=Q(y,{includePending:!i.showTypeColumn}),m=s.length?`
      <tr>
        <td>Total</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        <td>${l(d(u.items))}</td>
        <td>${l(d(u.taken))}</td>
        <td>${l(d(u.returned))}</td>
        <td></td>
      </tr>
  `:"",w=i.showTypeColumn?"<td>Total</td><td></td><td></td><td></td>":"<td>Total</td><td></td><td></td>",h=y.length?`
      <tr>
        ${w}
        <td>${l(d(f.events))}</td>
        <td>${l(i.showTypeColumn?"-":d(f.pending))}</td>
        <td>${l(d(f.taken))}</td>
        <td>${l(d(f.returned))}</td>
        <td>${l(d(f.notReturned))}</td>
        <td>${l(d(f.remaining))}</td>
        <td></td>
      </tr>
  `:"",j=y.length?`
    <br>
    <table border="1">
      <tr><th colspan="${x}">${l(i.productReportTitle||"Product Report")}</th></tr>
      <tr>${g.map(([o])=>`<th>${l(o)}</th>`).join("")}</tr>
      ${v}
      ${h}
    </table>
  `:"",$=`
    <table border="1">
      <tr><th colspan="${p}">${l(e)}</th></tr>
      <tr><td colspan="${p}">${l(n)}</td></tr>
      <tr><td colspan="${p}">Generated: ${l(r)}</td></tr>
      <tr>${z.map(([o])=>`<th>${l(o)}</th>`).join("")}</tr>
      ${c||`<tr><td colspan="${p}">No marketing requests found.</td></tr>`}
      ${m}
    </table>
    ${j}
  `,k=new Blob([`\uFEFF${$}`],{type:"application/vnd.ms-excel;charset=utf-8;"}),_=document.createElement("a");_.href=URL.createObjectURL(k),_.download=`${e.toLowerCase().replace(/[^a-z0-9]+/g,"-")}-${new Date().toISOString().slice(0,10)}.xls`,document.body.appendChild(_),_.click(),URL.revokeObjectURL(_.href),_.remove()}function rt(e,n,s,a=[],i={}){document.getElementById("print-root")?.remove();const r=document.createElement("div");r.id="print-root";const p=s.map((j,$)=>`<tr>${z.map(([,k],_)=>`<td${_>=8&&_<=10?' class="num"':""}>${l(F(k(j,$)))}</td>`).join("")}</tr>`).join(""),c=a,y=i.productColumns||re(i.productNameLabel,i.showTypeColumn),g=i.showTypeColumn?1:0,x=c.map((j,$)=>`<tr>${y.map(([,k],_)=>`<td${_>=3+g&&_<=8+g?' class="num"':""}>${l(F(k(j,$)))}</td>`).join("")}</tr>`).join(""),v=H(s),u=Q(c,{includePending:!i.showTypeColumn}),f=s.length?`
        <tfoot>
          <tr>
            <td colspan="8">Total</td>
            <td class="num">${l(d(v.items))}</td>
            <td class="num">${l(d(v.taken))}</td>
            <td class="num">${l(d(v.returned))}</td>
            <td></td>
          </tr>
        </tfoot>
  `:"",m=c.length?`
      <h2>${l(i.productReportTitle||"Product Report")}</h2>
      <table>
        <thead><tr>${y.map(([j],$)=>`<th${$>=3+g&&$<=8+g?' class="num"':""}>${l(j)}</th>`).join("")}</tr></thead>
        <tbody>${x}</tbody>
        <tfoot>
          <tr>
            <td colspan="${3+g}">Total</td>
            <td class="num">${l(d(u.events))}</td>
            <td class="num">${l(i.showTypeColumn?"-":d(u.pending))}</td>
            <td class="num">${l(d(u.taken))}</td>
            <td class="num">${l(d(u.returned))}</td>
            <td class="num">${l(d(u.notReturned))}</td>
            <td class="num">${l(d(u.remaining))}</td>
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
        <thead><tr>${z.map(([j],$)=>`<th${$>=8&&$<=10?' class="num"':""}>${l(j)}</th>`).join("")}</tr></thead>
        <tbody>${p||`<tr><td colspan="${z.length}">No marketing requests found.</td></tr>`}</tbody>
        ${f}
      </table>
      ${m}
    </div>
  `,document.body.appendChild(r);function w(){document.body.classList.remove("printing-panel","printing-marketing-report"),r.remove(),window.removeEventListener("afterprint",w),window.clearTimeout(h)}const h=window.setTimeout(w,6e4);document.body.classList.add("printing-panel","printing-marketing-report"),window.addEventListener("afterprint",w),window.print()}function ze(e){const n=e.take||{},s=e.items||[],a=e.settings||e.invoice_settings||{},i=a.company_name||"Shadow Group Co., Ltd.",r=a.company_phone||"",p=a.company_email||"",c=a.company_address||"",y=a.contact_person||"",g=e.logo_url||e.invoice_logo_url||a.logo_url||"",x=g?`${g}${g.includes("?")?"&":"?"}v=${Date.now()}`:"",v=Math.max(40,Math.min(200,Number(a.logo_width||80))),u=Math.max(40,Math.min(200,Number(a.logo_height||70))),f=n.status==="completed",m=n.display_code||`MT-#${n.id||""}`,w=J(s),h=n.marketing_type_label||q(n.marketing_type),j=f?'<th style="width:16%;">បានប្រគល់<span class="th-en">Returned</span></th><th style="width:16%;">មិនប្រគល់<span class="th-en">Not Returned</span></th>':"",$=s.map((M,b)=>`
    <tr>
      <td class="tc">${b+1}</td>
      <td><strong>${l(M.product_name||"-")}</strong>${M.sku?`<div class="sku">${l(M.sku)}</div>`:""}</td>
      <td class="tc">${l(d(M.quantity_taken))}</td>
      ${f?`<td class="tc">${l(d(M.quantity_returned))}</td><td class="tc">${l(d(M.quantity_not_returned))}</td>`:""}
    </tr>
  `).join("")||`<tr><td colspan="${f?5:3}" class="tc">គ្មានផលិតផល / No products</td></tr>`,k=f?`<td class="tc">${l(d(w.returned))}</td><td class="tc">${l(d(w.notReturned))}</td>`:"",_=[y?`<div>ទំនាក់ទំនង / Contact: ${l(y)}</div>`:"",c?`<div>${l(c).replace(/\n/g,"<br>")}</div>`:"",r?`<div>ទូរស័ព្ទ / Phone: ${l(r)}</div>`:"",p?`<div>អ៊ីមែល / Email: ${l(p)}</div>`:""].filter(Boolean).join(""),o=Se(n.event_date),N=Te(n.event_date),E=[["លេខវិក្កយបត្រ","Invoice No",m],["កាលបរិច្ឆេទ","Date",N===o?o:`${N} / ${o}`],["ព្រឹត្តិការណ៍","Event",n.event_name||"-"],["ទូរស័ព្ទ","Phone",n.phone||"-"],["ទីតាំង","Location",n.location||"-"],["ប្រភេទ","Type",h||"-"],["ប្រភេទដឹកជញ្ជូន","Delivery Type",n.delivery_type_name||"-"]].map(([M,b,S])=>`<tr><td><span class="lbl-km">${l(M)}</span><span class="lbl-en">${l(b)}</span></td><td>${l(S)}</td></tr>`).join(""),P=f?"វិក្កយបត្រផ្ទៀងផ្ទាត់ទីផ្សារ":"វិក្កយបត្រទីផ្សារ",A=f?"MARKETING RECONCILE":"MARKETING INVOICE";return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <title>${l(m)} ${A}</title>
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
    .logo img { max-width: ${v}px; max-height: ${u}px; object-fit: contain; display: block; }
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
            ${_?`<div class="inv-company-info">${_}</div>`:""}
          </div>
        </div>
      </div>
      <div class="inv-top-right">
        <div class="inv-title-km">${P}</div>
        <div class="inv-title-en">${A}</div>
        <table class="inv-info-tbl">${E}</table>
      </div>
    </div>
    <hr class="inv-divider">
    <table class="inv-items">
      <thead>
        <tr>
          <th class="tl" style="width:8%;">ល.រ<span class="th-en">No</span></th>
          <th class="tl">ផលិតផល<span class="th-en">Product</span></th>
          <th style="width:16%;">ចំនួនយក<span class="th-en">Qty Taken</span></th>
          ${j}
        </tr>
      </thead>
      <tbody>${$}</tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="tc">សរុប / TOTAL</td>
          <td class="tc">${l(d(w.taken))}</td>
          ${k}
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
</html>`}function X(e,n){const s=document.createElement("iframe");s.style.cssText="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;",document.body.appendChild(s);const a=s.contentWindow,i=a?.document||s.contentDocument;if(!i){s.remove(),window.alert("Unable to open print window.");return}i.open(),i.write(e),i.close();const r=()=>{try{a?.focus?.(),a?.print?.()}catch(c){console.error("Print failed:",c)}window.setTimeout(()=>{try{s.remove()}catch{}},800)},p=()=>{const c=[...i.images||[]],y=n?i.getElementById(n):null;return y&&!c.includes(y)&&c.push(y),!c.length||c.every(g=>g.complete)?Promise.resolve():Promise.all(c.map(g=>new Promise(x=>{if(g.complete)return x();const v=()=>x();g.addEventListener("load",v,{once:!0}),g.addEventListener("error",v,{once:!0}),window.setTimeout(v,1500)})))};Promise.resolve().then(()=>i.fonts?.ready).catch(()=>{}).then(p).then(()=>window.setTimeout(r,80))}function Be(e){X(ze(e||{}),"marketing-invoice-logo")}function le(e){const n=e.take||{},s=e.items||[],a=e.settings||e.invoice_settings||{},i=J(s),r=s.map((g,x)=>`
    <tr>
      <td class="no">${x+1}</td>
      <td class="product">${l(g.product_name||"-")}</td>
      <td class="qty">${l(d(g.quantity_taken))}</td>
    </tr>
  `).join("")||'<tr><td colspan="3" class="empty">No products.</td></tr>',p=e.logo_url||e.invoice_logo_url||a.logo_url||"",c=n.display_code||`MT-#${n.id||""}`,y=`https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=${encodeURIComponent(c)}`;return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delivery Slip - ${l(c)}</title>
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
    <div class="receipt-header-logo">${p?`<img id="delivery-slip-logo" src="${l(p)}" alt="Logo">`:""}</div>
    <div class="receipt-title">&#x1794;&#x178E;&#x17D2;&#x178E;&#x178A;&#x17B9;&#x1780; / Delivery Slip</div>
    <div class="top-line">
      <div>
        <div><span class="label-col">Created By:</span> <span class="value-col">${l(n.created_by_name||"-")}</span></div>
        <div class="code">Code: ${l(c)}</div>
      </div>
      <img src="${l(y)}" alt="QR" class="receipt-qr">
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
          <td class="qty-total-value">${l(d(i.taken))}</td>
        </tr>
      </tfoot>
    </table>
    <div class="section-divider"></div>
    <div class="section-title">Delivery</div>
    <div class="delivery-info-line">
      <span class="delivery-label">&#x178A;&#x17B9;&#x1780;&#x178A;&#x17C4;&#x1799;</span>
      <span class="delivery-value">${l(n.delivery_type_name||"Marketing")}</span>
    </div>
    <div class="info-row"><span class="label-col">Type</span><span class="value-col">${l(n.marketing_type_label||q(n.marketing_type))}</span></div>
    <div class="info-row"><span class="label-col">Created</span><span class="value-col">${l(n.created_at||"-")}</span></div>
    <div class="receipt-powered-by">Powered by : One Night Solution</div>
    <div class="thermal-cut-feed" aria-hidden="true"></div>
  </div>
</body>
</html>`}function Ae(e){X(le(e||{}),"delivery-slip-logo")}async function oe(e){const n=e?.take?.id||e?.id;return n?e?.take&&Array.isArray(e?.items)?e:Ue(n):null}async function lt(e){const n=await oe(e);n&&Be(n)}function Ie(e){const n=e.indexOf('<div class="invoice receipt-card">'),s=e.lastIndexOf("</body>");return n<0?"":e.slice(n,s===-1?e.length:s).trim()}function Fe(e){const n=e.map(a=>le(a)),s=n.slice(1).map(Ie).join(`
`);return n[0].replace("</style>",".invoice { page-break-after: always; } .invoice:last-of-type { page-break-after: auto; }</style>").replace("</body>",`${s}</body>`)}async function Ke(e){const n=(e||[]).filter(B);if(!n.length)throw new Error("Cannot print delivery note while the request is pending approval.");const s=[];for(const a of n){const i=await oe(a);i&&Y(i)||i&&s.push(i)}if(!s.length)throw new Error("Cannot print delivery note while the request is pending approval.");if(s.length===1){Ae(s[0]);return}X(Fe(s))}async function Ue(e){return G("/api/reports/marketing.php",{action:"detail",id:e})}function ot({children:e,onExcel:n,onPrint:s,onDeliveryNote:a,selectedCount:i=0,printing:r=!1,disabled:p}){return t.jsxs("div",{className:"marketing-report-actions",children:[t.jsxs("button",{type:"button",onClick:n,disabled:p,children:[t.jsx(me,{size:15})," ",t.jsx("span",{children:"Excel"})]}),t.jsxs("button",{type:"button",onClick:s,disabled:p,children:[t.jsx(pe,{size:15})," ",t.jsx("span",{children:"Print"})]}),a?t.jsxs("button",{type:"button",onClick:a,disabled:r||i<1,children:[r?t.jsx(O,{className:"spin",size:15}):t.jsx(ee,{size:15}),t.jsxs("span",{children:["Delivery Note",i?` (${i})`:""]})]}):null,e]})}function dt({row:e,onDetail:n,onInvoice:s,onEdit:a}){return t.jsxs("div",{className:"marketing-row-actions is-icons",children:[t.jsx("button",{type:"button",title:"View","aria-label":"View",onClick:()=>n(e),children:t.jsx(xe,{size:14})}),a?t.jsx("button",{type:"button",title:"Edit","aria-label":"Edit",onClick:()=>a(e),children:t.jsx(ve,{size:14})}):null,s&&["pending","completed"].includes(e.status)?t.jsx("button",{type:"button",title:"Invoice","aria-label":"Invoice",onClick:()=>s(e),children:t.jsx(ee,{size:14})}):null]})}export{dt as D,st as M,ot as R,tt as a,et as b,Je as c,Xe as d,rt as e,it as f,Ee as g,Ze as h,nt as i,at as j,Qe as k,Ue as l,q as m,je as n,lt as p,He as q,We as u};
