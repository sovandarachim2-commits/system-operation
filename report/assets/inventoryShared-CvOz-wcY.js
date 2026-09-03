import{j as o,r as v,s as C}from"./index-BOHyaEv4.js";import{r as L}from"./index-UFiCzsma.js";import{n as I}from"./purchaseShared-BaxQeVfq.js";function D({cards:e=[],className:t="",loading:n=!1}){return o.jsx("section",{className:`purchase-kpi-grid ${t}`.trim(),children:e.map(a=>{const[i,f,h,l=""]=a,y=n||String(h||"").toLowerCase()==="loading...";return o.jsxs("article",{className:l?`tone-${l}`:void 0,children:[o.jsx("span",{children:i}),y?o.jsx("b",{className:"skeleton-line inventory-kpi-loading"}):o.jsx("strong",{children:f}),!y&&h?o.jsx("small",{children:h}):null]},i)})})}function B({title:e,rowsCount:t,actions:n,children:a}){return o.jsxs("section",{className:"data-panel purchase-table-panel",children:[o.jsxs("div",{className:"panel-header",children:[o.jsxs("div",{children:[o.jsx("h2",{children:e}),o.jsx("span",{children:t})]}),n?o.jsx("div",{className:"panel-actions",children:n}):null]}),o.jsx("div",{className:"table-wrap",children:a})]})}function T({label:e="Action",items:t=[]}){const[n,a]=v.useState(!1),[i,f]=v.useState(null),h=v.useRef(`inventory-action-${Math.random().toString(36).slice(2)}`);function l(){a(!1),f(null)}function y(r){if(r.preventDefault(),r.stopPropagation(),n){l();return}const c=r.currentTarget.getBoundingClientRect(),m=176,u=Math.min(280,8+t.length*40),b=Math.max(12,Math.min(window.innerWidth-m-12,c.right-m)),g=c.bottom+u+12>window.innerHeight?Math.max(12,c.top-u-6):c.bottom+6;window.dispatchEvent(new CustomEvent("inventory-action-menu-open",{detail:h.current})),f({top:g,left:b,width:m}),a(!0)}return v.useEffect(()=>{if(!n)return;const r=u=>{u.detail!==h.current&&l()},c=()=>l(),m=u=>{u.key==="Escape"&&l()};return window.addEventListener("inventory-action-menu-open",r),window.addEventListener("click",c),window.addEventListener("scroll",c,!0),window.addEventListener("resize",c),window.addEventListener("keydown",m),()=>{window.removeEventListener("inventory-action-menu-open",r),window.removeEventListener("click",c),window.removeEventListener("scroll",c,!0),window.removeEventListener("resize",c),window.removeEventListener("keydown",m)}},[n]),o.jsxs("div",{className:"inventory-delivery-actions",children:[o.jsxs("button",{type:"button",className:`inventory-delivery-action-trigger${n?" active":""}`,"aria-haspopup":"menu","aria-expanded":n,onClick:y,children:[o.jsx("span",{children:e}),o.jsx(C,{size:14})]}),n&&i?L.createPortal(o.jsx("div",{className:"inventory-delivery-action-menu",role:"menu",style:{top:i.top,left:i.left,width:i.width},onClick:r=>r.stopPropagation(),children:t.map(r=>o.jsxs("button",{type:"button",role:"menuitem",className:r.danger?"danger":"",disabled:r.disabled,onClick:()=>{l(),r.onClick?.()},children:[r.icon,r.label]},r.id))}),document.body):null]})}function O(e,t,n,a={}){const i=x=>String(x??"").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;"),f=Array.isArray(a.titleLines)?a.titleLines.filter(Boolean):[],h=n.reduce((x,g)=>Math.max(x,Array.isArray(g)?g.length:0),0),l=Math.max(t.length,h,1),y=f.map((x,g)=>`<tr><td colspan="${l}" style="font-size:${g===0?16:12}px;font-weight:${g===0?700:500};">${i(x)}</td></tr>`);y.length&&y.push(`<tr><td colspan="${l}"></td></tr>`);const c=`<!doctype html><html><head><meta charset="utf-8"></head><body><table border="1">${[...y,...t.length?[`<tr>${t.map(x=>`<th>${i(x)}</th>`).join("")}</tr>`]:[],...n.map(x=>`<tr>${x.map(g=>`<td>${i(g)}</td>`).join("")}</tr>`)].join("")}</table></body></html>`,m=new Blob([c],{type:"application/vnd.ms-excel;charset=utf-8;"}),u=URL.createObjectURL(m),b=document.createElement("a");b.href=u,b.download=`${e}.xls`,document.body.appendChild(b),b.click(),b.remove(),URL.revokeObjectURL(u)}function P(e){if(!e)return"—";const t=new Date(String(e).replace(" ","T"));return Number.isNaN(t.getTime())?String(e):t.toLocaleString("en-US",{month:"short",day:"numeric",year:"numeric",hour:"2-digit",minute:"2-digit"})}function U({urls:e=[]}){const t=(e||[]).filter(Boolean);return t.length?o.jsx("div",{className:"inventory-image-thumbs",children:t.map(n=>o.jsx("a",{href:n,target:"_blank",rel:"noreferrer",className:"inventory-image-thumb",children:o.jsx("img",{src:n,alt:"",loading:"lazy"})},n))}):o.jsx("span",{className:"inventory-images-empty",children:"—"})}function _(e={}){const t=String(e.document_code||e.transfer_code||e.reference_id||"").trim();if(!t)return"";const n=t.toLowerCase();return["transfer","adjustment","in","out"].includes(n)?"":t}function R(e={}){const t=String(e.created_by_name||e.created_by||"").trim(),n=t.match(/^(.*?)\s*\(([^)]+)\)\s*$/);return n&&n[1].trim()?{name:n[1].trim(),username:""}:{name:t||"—",username:""}}function q({row:e,className:t="col-user"}){const{name:n}=R(e);return o.jsx("span",{className:`inventory-user-label ${t}`.trim(),children:o.jsx("strong",{children:n})})}function K(e,t="",n=""){let a=String(e||"").trim();if(!a||a==="—")return"";const i=String(t||"").trim(),f=String(n||"").trim();if(i&&f){const h=[`${i} → ${f}`,`${i} -> ${f}`,`${i} to ${f}`];for(const l of h){if(a===l)return"";(a.startsWith(`${l} `)||a.startsWith(`${l}.`))&&(a=a.slice(l.length).replace(/^[\s.|:-]+/,"").trim())}}return a}function H(e=[]){const t=new Map;return e.forEach(n=>{const a=_(n),i=a||`row:${n.id}`;t.has(i)||t.set(i,{key:i,document_code:a||"—",movement_date:n.movement_date,rows:[]}),t.get(i).rows.push(n)}),Array.from(t.values())}function F({value:e="single",onChange:t,singleLabel:n="Single row",groupLabel:a="Group by code"}){return o.jsxs("div",{className:"inventory-view-mode",role:"group","aria-label":"Row view mode",children:[o.jsx("button",{type:"button",className:e==="single"?"active":"",onClick:()=>t?.("single"),children:n}),o.jsx("button",{type:"button",className:e==="group"?"active":"",onClick:()=>t?.("group"),children:a})]})}function G({value:e="",options:t=[],fallbackLabel:n="",onChange:a,onSearch:i,placeholder:f="Search product..."}){const h=v.useRef(null),l=v.useRef(null),y=v.useRef(null),[r,c]=v.useState(!1),[m,u]=v.useState(""),[b,x]=v.useState(null),g=v.useMemo(()=>(t||[]).find(s=>String(s.value)===String(e))||null,[t,e]),j=g?String(g.label||g.name||"").trim():e?String(n||"").trim():"";v.useEffect(()=>{j?u(j):e||u(s=>r?s:"")},[j,e,r]),v.useEffect(()=>{function s(d){const p=d.target;h.current?.contains(p)||y.current?.contains(p)||(c(!1),j?u(j):e||u(""))}return document.addEventListener("mousedown",s),()=>document.removeEventListener("mousedown",s)},[j,e]);const k=v.useMemo(()=>{const s=t||[],d=m.trim().toLowerCase();return!d||j&&d===j.toLowerCase()?s.slice(0,80):s.filter(p=>`${p.label||""} ${p.name||""} ${p.sku||""}`.toLowerCase().includes(d)).slice(0,80)},[t,m,j]);function N(s={}){const d=s.available_stock??s.available_quantity??s.quantity_on_hand??s.stock_available??s.stock;if(d==null||d==="")return"";const p=Number(d);return Number.isFinite(p)?I.format(p):String(d)}return v.useEffect(()=>{if(!r){x(null);return}function s(){const d=l.current;if(!d)return;const p=d.getBoundingClientRect(),S=4,E=Math.min(240,Math.max(140,window.innerHeight-p.bottom-12)),$=window.innerHeight-p.bottom<160&&p.top>180;x({position:"fixed",left:Math.max(8,p.left),width:Math.max(180,p.width),maxHeight:E,zIndex:5e3,top:$?void 0:p.bottom+S,bottom:$?window.innerHeight-p.top+S:void 0})}return s(),window.addEventListener("resize",s),window.addEventListener("scroll",s,!0),()=>{window.removeEventListener("resize",s),window.removeEventListener("scroll",s,!0)}},[r,k.length,m]),o.jsxs("div",{className:`inventory-product-search${r?" open":""}`,ref:h,children:[o.jsx("input",{ref:l,type:"text",value:m,placeholder:f,autoComplete:"off",onFocus:()=>{c(!0),i?.(m===j?"":m)},onChange:s=>{const d=s.target.value;u(d),c(!0),e&&a?.(""),i?.(d)}}),r&&b?L.createPortal(o.jsx("div",{className:"inventory-product-search-menu",role:"listbox",ref:y,style:b,children:k.length?k.map(s=>{const d=N(s);return o.jsxs("button",{type:"button",role:"option",className:String(s.value)===String(e)?"active":"","aria-selected":String(s.value)===String(e),onMouseDown:p=>p.preventDefault(),onClick:()=>{a?.(String(s.value),s),u(String(s.label||s.name||"").trim()),c(!1)},children:[o.jsxs("span",{className:"inventory-product-option-text",children:[o.jsx("strong",{children:s.name||s.label}),s.sku?o.jsx("small",{children:s.sku}):null]}),d?o.jsxs("span",{className:"inventory-product-option-stock",children:["Stock ",d]}):null]},s.value)}):o.jsx("div",{className:"inventory-product-search-empty",children:"No products found"})}),document.body):null]})}function W(){const e=new Date,t=String(e.getFullYear()).slice(-2),n=String(e.getMonth()+1).padStart(2,"0");return`TRF-${t}${n}001`}function Q(e,t){if(!e)return!1;if(e.username==="admin"||String(e.role||"").toLowerCase()==="admin")return!0;const n=Array.isArray(e.permissions)?e.permissions:[];return({view:["sr_inventory_delivery_notes.view"],update:["sr_inventory_delivery_notes.update"],delete:["sr_inventory_delivery_notes.delete"]}[t]||[]).some(i=>n.includes(i))}function w(e=""){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function Y({logoUrl:e="",companyName:t="Shadow Group Co., Ltd.",companyPhone:n="",companyEmail:a="",slipCode:i="",receiverName:f="—",receiverPhone:h="—",receiverPlace:l="",deliveryDateText:y="—",itemCount:r=0,totalQtyText:c="0",slipNote:m="",itemRowsHtml:u=""}={}){const g=[n?`<div class="contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg><span>${w(n)}</span></div>`:"",a?`<div class="contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><span>${w(a)}</span></div>`:""].filter(Boolean).join("");return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${w(i||"Delivery Note")}</title>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Khmer:wght@400;600;700;800&amp;display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #0f172a;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", "Segoe UI", Arial, sans-serif;
    }
    .invoice {
      width: 100%;
      max-width: 190mm;
      margin: 0 auto;
      padding: 14mm 16mm 16mm;
      background: #fff;
      color: #0f172a;
    }
    .accent {
      height: 8px;
      margin: -14mm -16mm 16mm;
      background: linear-gradient(90deg, #0f766e 0%, #14b8a6 55%, #2563eb 100%);
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 24px;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 16px;
      min-width: 0;
    }
    .logo img {
      max-width: 78px;
      max-height: 78px;
      object-fit: contain;
      display: block;
    }
    .brand-text h1 {
      margin: 0;
      font-size: 26px;
      font-weight: 800;
      letter-spacing: .01em;
      line-height: 1.2;
      color: #0f766e;
    }
    .contacts {
      margin-top: 8px;
      display: grid;
      gap: 4px;
      color: #334155;
      font-size: 13px;
    }
    .contact {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .contact svg {
      width: 14px;
      height: 14px;
      flex: 0 0 14px;
      color: #0f766e;
    }
    .slip-box {
      min-width: 200px;
      border: 1px solid #99f6e4;
      border-radius: 12px;
      background: #ecfdf5;
      padding: 12px 18px 14px;
      text-align: center;
    }
    .slip-box span {
      display: block;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .08em;
      color: #0f766e;
    }
    .slip-box strong {
      display: block;
      margin-top: 6px;
      font-size: 20px;
      font-weight: 800;
      letter-spacing: .02em;
      color: #115e59;
    }
    .rule {
      border: 0;
      border-top: 1px solid #99f6e4;
      margin: 16px 0;
    }
    .meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 48px;
    }
    .meta-row {
      display: grid;
      grid-template-columns: 130px 12px minmax(0, 1fr);
      align-items: start;
      font-size: 15px;
      line-height: 1.5;
    }
    .meta-row .label, .meta-row .colon { font-weight: 700; color: #0f766e; }
    .meta-row .value { font-weight: 600; overflow-wrap: anywhere; color: #0f172a; }
    .note {
      display: grid;
      grid-template-columns: 130px 12px minmax(0, 1fr);
      font-size: 14px;
      line-height: 1.55;
      font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", "Segoe UI", Arial, sans-serif;
    }
    .note .label, .note .colon { font-weight: 700; color: #0f766e; }
    .note .value { overflow-wrap: anywhere; color: #334155; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 18px;
      font-size: 15px;
      border: 1px solid #000;
    }
    th, td {
      border: 1px solid #000;
      padding: 10px 12px;
      vertical-align: middle;
      color: #000;
    }
    th {
      background: #f1f5f9;
      color: #000;
      font-weight: 800;
      text-align: center;
      font-size: 14px;
    }
    tbody tr:nth-child(even) td { background: #fff; }
    td.no, td.qty { text-align: center; width: 80px; }
    td.product { text-align: left; }
    tfoot td {
      background: #f8fafc;
      color: #000;
      font-weight: 800;
      font-size: 16px;
      border-top: 1px solid #000;
    }
    tfoot .total-label { text-align: center; }
    tfoot .total-qty { text-align: center; color: #000; }
    .signs {
      display: flex;
      justify-content: space-between;
      gap: 48px;
      margin-top: 64px;
      padding: 0 12px;
    }
    .sign {
      width: 42%;
      text-align: center;
      font-size: 15px;
      font-weight: 700;
      color: #0f766e;
    }
    .sign-line {
      border-top: 1px solid #0f766e;
      margin-top: 56px;
    }
    @media print {
      .invoice {
        max-width: none;
        margin: 0;
        box-shadow: none;
      }
    }
  </style>
</head>
<body>
  <div class="invoice">
    <div class="accent"></div>
    <div class="header">
      <div class="brand">
        <div class="logo">${e?`<img id="delivery-slip-logo" src="${w(e)}" alt="Logo">`:""}</div>
        <div class="brand-text">
          <h1>${w(t||"Shadow Group Co., Ltd.")}</h1>
          ${g?`<div class="contacts">${g}</div>`:""}
        </div>
      </div>
      <div class="slip-box">
        <span>SLIP CODE</span>
        <strong>${w(i)}</strong>
      </div>
    </div>
    <hr class="rule">
    <div class="meta">
      <div>
        <div class="meta-row"><span class="label">Receiver</span><span class="colon">:</span><span class="value">${w(f)}</span></div>
        <div class="meta-row"><span class="label">Phone</span><span class="colon">:</span><span class="value">${w(h)}</span></div>
        <div class="meta-row"><span class="label">Address</span><span class="colon">:</span><span class="value">${w(l||"—")}</span></div>
      </div>
      <div>
        <div class="meta-row"><span class="label">Delivery Date</span><span class="colon">:</span><span class="value">${w(y)}</span></div>
        <div class="meta-row"><span class="label">Items</span><span class="colon">:</span><span class="value">${w(String(r))}</span></div>
        <div class="meta-row"><span class="label">Total Qty</span><span class="colon">:</span><span class="value">${w(c)}</span></div>
      </div>
    </div>
    <hr class="rule">
    <div class="note">
      <span class="label">Note</span><span class="colon">:</span>
      <span class="value">${m?w(m).replace(/\r\n|\n|\r/g,"<br>"):"—"}</span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:10%;">No.</th>
          <th style="width:70%;">Product</th>
          <th style="width:20%;">Qty</th>
        </tr>
      </thead>
      <tbody>${u}</tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="total-label">TOTAL</td>
          <td class="total-qty">${w(c)}</td>
        </tr>
      </tfoot>
    </table>
    <div class="signs">
      <div class="sign">Delivered By<div class="sign-line"></div></div>
      <div class="sign">Received By<div class="sign-line"></div></div>
    </div>
  </div>
</body>
</html>`}export{D as I,F as a,B as b,U as c,O as d,q as e,P as f,H as g,G as h,_ as i,Q as j,T as k,Y as l,K as m,R as n,W as s};
