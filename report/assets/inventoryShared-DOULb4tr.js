import{j as s,r as h,h as I}from"./index-BfsxGsel.js";import{r as N}from"./index-C87wc4_V.js";import{n as _}from"./purchaseShared-CgNP4pYz.js";function B({cards:e=[],className:t="",loading:n=!1}){return s.jsx("section",{className:`purchase-kpi-grid ${t}`.trim(),children:e.map(a=>{const[r,v,u,c=""]=a,w=n||String(u||"").toLowerCase()==="loading...";return s.jsxs("article",{className:c?`tone-${c}`:void 0,children:[s.jsx("span",{children:r}),w?s.jsx("b",{className:"skeleton-line inventory-kpi-loading"}):s.jsx("strong",{children:v}),!w&&u?s.jsx("small",{children:u}):null]},r)})})}function T({title:e,rowsCount:t,actions:n,children:a}){return s.jsxs("section",{className:"data-panel purchase-table-panel",children:[s.jsxs("div",{className:"panel-header",children:[s.jsxs("div",{children:[s.jsx("h2",{children:e}),s.jsx("span",{children:t})]}),n?s.jsx("div",{className:"panel-actions",children:n}):null]}),s.jsx("div",{className:"table-wrap",children:a})]})}function O({label:e="Action",items:t=[]}){const[n,a]=h.useState(!1),[r,v]=h.useState(null),u=h.useRef(`inventory-action-${Math.random().toString(36).slice(2)}`);function c(){a(!1),v(null)}function w(i){if(i.preventDefault(),i.stopPropagation(),n){c();return}const l=i.currentTarget.getBoundingClientRect(),f=176,g=Math.min(280,8+t.length*40),y=Math.max(12,Math.min(window.innerWidth-f-12,l.right-f)),d=l.bottom+g+12>window.innerHeight?Math.max(12,l.top-g-6):l.bottom+6;window.dispatchEvent(new CustomEvent("inventory-action-menu-open",{detail:u.current})),v({top:d,left:y,width:f}),a(!0)}return h.useEffect(()=>{if(!n)return;const i=g=>{g.detail!==u.current&&c()},l=()=>c(),f=g=>{g.key==="Escape"&&c()};return window.addEventListener("inventory-action-menu-open",i),window.addEventListener("click",l),window.addEventListener("scroll",l,!0),window.addEventListener("resize",l),window.addEventListener("keydown",f),()=>{window.removeEventListener("inventory-action-menu-open",i),window.removeEventListener("click",l),window.removeEventListener("scroll",l,!0),window.removeEventListener("resize",l),window.removeEventListener("keydown",f)}},[n]),s.jsxs("div",{className:"inventory-delivery-actions",children:[s.jsxs("button",{type:"button",className:`inventory-delivery-action-trigger${n?" active":""}`,"aria-haspopup":"menu","aria-expanded":n,onClick:w,children:[s.jsx("span",{children:e}),s.jsx(I,{size:14})]}),n&&r?N.createPortal(s.jsx("div",{className:"inventory-delivery-action-menu",role:"menu",style:{top:r.top,left:r.left,width:r.width},onClick:i=>i.stopPropagation(),children:t.map(i=>s.jsxs("button",{type:"button",role:"menuitem",className:i.danger?"danger":"",disabled:i.disabled,onClick:()=>{c(),i.onClick?.()},children:[i.icon,i.label]},i.id))}),document.body):null]})}function P(e,t,n,a={}){const r=d=>String(d??"").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;"),v=Array.isArray(a.titleLines)?a.titleLines.filter(Boolean):[],u=d=>a.preserveLineBreaks?r(d).replace(/\r?\n/g,'<br style="mso-data-placement:same-cell;">'):r(d),c=n.reduce((d,b)=>Math.max(d,Array.isArray(b)?b.length:0),0),w=Math.max(t.length,c,1),i=v.map((d,b)=>`<tr><td colspan="${w}" style="font-size:${b===0?16:12}px;font-weight:${b===0?700:500};">${r(d)}</td></tr>`);i.length&&i.push(`<tr><td colspan="${w}"></td></tr>`);const f=`<!doctype html><html><head><meta charset="utf-8"></head><body><table border="1">${[...i,...t.length?[`<tr>${t.map(d=>`<th>${r(d)}</th>`).join("")}</tr>`]:[],...n.map(d=>`<tr>${d.map(b=>`<td>${u(b)}</td>`).join("")}</tr>`)].join("")}</table></body></html>`,g=new Blob([f],{type:"application/vnd.ms-excel;charset=utf-8;"}),y=URL.createObjectURL(g),k=document.createElement("a");k.href=y,k.download=`${e}.xls`,document.body.appendChild(k),k.click(),k.remove(),URL.revokeObjectURL(y)}function U(e){if(!e)return"—";const t=new Date(String(e).replace(" ","T"));return Number.isNaN(t.getTime())?String(e):t.toLocaleString("en-US",{month:"short",day:"numeric",year:"numeric",hour:"2-digit",minute:"2-digit"})}function q({urls:e=[]}){const t=(e||[]).filter(Boolean);return t.length?s.jsx("div",{className:"inventory-image-thumbs",children:t.map(n=>s.jsx("a",{href:n,target:"_blank",rel:"noreferrer",className:"inventory-image-thumb",children:s.jsx("img",{src:n,alt:"",loading:"lazy"})},n))}):s.jsx("span",{className:"inventory-images-empty",children:"—"})}function R(e={}){const t=String(e.document_code||e.transfer_code||e.reference_id||"").trim();if(!t)return"";const n=t.toLowerCase();return["transfer","adjustment","in","out"].includes(n)?"":t}function z(e={}){const t=String(e.created_by_name||e.created_by||"").trim(),n=t.match(/^(.*?)\s*\(([^)]+)\)\s*$/);return n&&n[1].trim()?{name:n[1].trim(),username:""}:{name:t||"—",username:""}}function K({row:e,className:t="col-user"}){const{name:n}=z(e);return s.jsx("span",{className:`inventory-user-label ${t}`.trim(),children:s.jsx("strong",{children:n})})}function H(e,t="",n=""){let a=String(e||"").trim();if(!a||a==="—")return"";const r=String(t||"").trim(),v=String(n||"").trim();if(r&&v){const u=[`${r} → ${v}`,`${r} -> ${v}`,`${r} to ${v}`];for(const c of u){if(a===c)return"";(a.startsWith(`${c} `)||a.startsWith(`${c}.`))&&(a=a.slice(c.length).replace(/^[\s.|:-]+/,"").trim())}}return a}function F(e=[]){const t=new Map;return e.forEach(n=>{const a=R(n),r=a||`row:${n.id}`;t.has(r)||t.set(r,{key:r,document_code:a||"—",movement_date:n.movement_date,rows:[]}),t.get(r).rows.push(n)}),Array.from(t.values())}function G({value:e="single",onChange:t,singleLabel:n="Single row",groupLabel:a="Group by code"}){return s.jsxs("div",{className:"inventory-view-mode",role:"group","aria-label":"Row view mode",children:[s.jsx("button",{type:"button",className:e==="single"?"active":"",onClick:()=>t?.("single"),children:n}),s.jsx("button",{type:"button",className:e==="group"?"active":"",onClick:()=>t?.("group"),children:a})]})}function W({value:e="",options:t=[],fallbackLabel:n="",onChange:a,onSearch:r,placeholder:v="Search product...",disabled:u=!1}){const c=h.useRef(null),w=h.useRef(null),i=h.useRef(null),[l,f]=h.useState(!1),[g,y]=h.useState(""),[k,d]=h.useState(null),b=h.useMemo(()=>(t||[]).find(o=>String(o.value)===String(e))||null,[t,e]),$=b?String(b.label||b.name||"").trim():e?String(n||"").trim():"";h.useEffect(()=>{$?y($):e||y(o=>l?o:"")},[$,e,l]),h.useEffect(()=>{u&&f(!1)},[u]),h.useEffect(()=>{function o(p){const m=p.target;c.current?.contains(m)||i.current?.contains(m)||(f(!1),$?y($):e||y(""))}return document.addEventListener("mousedown",o),()=>document.removeEventListener("mousedown",o)},[$,e]);const j=h.useMemo(()=>{const o=t||[],p=g.trim().toLowerCase();return!p||$&&p===$.toLowerCase()?o.slice(0,80):o.filter(m=>`${m.label||""} ${m.name||""} ${m.sku||""} ${m.barcode||""}`.toLowerCase().includes(p)).slice(0,80)},[t,g,$]);function E(o={}){const p=o.available_stock??o.available_quantity??o.quantity_on_hand??o.stock_available??o.stock;if(p==null||p==="")return"";const m=Number(p);return Number.isFinite(m)?_.format(m):String(p)}return h.useEffect(()=>{if(!l){d(null);return}function o(){const p=w.current;if(!p)return;const m=p.getBoundingClientRect(),S=4,C=Math.min(240,Math.max(140,window.innerHeight-m.bottom-12)),L=window.innerHeight-m.bottom<160&&m.top>180;d({position:"fixed",left:Math.max(8,m.left),width:Math.max(180,m.width),maxHeight:C,zIndex:5e3,top:L?void 0:m.bottom+S,bottom:L?window.innerHeight-m.top+S:void 0})}return o(),window.addEventListener("resize",o),window.addEventListener("scroll",o,!0),()=>{window.removeEventListener("resize",o),window.removeEventListener("scroll",o,!0)}},[l,j.length,g]),s.jsxs("div",{className:`inventory-product-search${l?" open":""}`,ref:c,children:[s.jsx("input",{ref:w,type:"text",disabled:u,value:g,placeholder:v,autoComplete:"off",onFocus:()=>{u||(f(!0),r?.(g===$?"":g))},onChange:o=>{if(u)return;const p=o.target.value;y(p),f(!0),e&&a?.(""),r?.(p)}}),l&&k?N.createPortal(s.jsx("div",{className:"inventory-product-search-menu",role:"listbox",ref:i,style:k,children:j.length?j.map(o=>{const p=E(o);return s.jsxs("button",{type:"button",role:"option",className:String(o.value)===String(e)?"active":"","aria-selected":String(o.value)===String(e),onMouseDown:m=>m.preventDefault(),onClick:()=>{a?.(String(o.value),o),y(String(o.label||o.name||"").trim()),f(!1)},children:[s.jsxs("span",{className:"inventory-product-option-text",children:[s.jsx("strong",{children:o.name||o.label}),o.sku?s.jsx("small",{children:o.sku}):null]}),p?s.jsxs("span",{className:"inventory-product-option-stock",children:["Stock ",p]}):null]},o.value)}):s.jsx("div",{className:"inventory-product-search-empty",children:"No products found"})}),document.body):null]})}function Q(){const e=new Date,t=String(e.getFullYear()).slice(-2),n=String(e.getMonth()+1).padStart(2,"0");return`TRF-${t}${n}001`}function Y(e,t){if(!e)return!1;if(e.username==="admin"||String(e.role||"").toLowerCase()==="admin")return!0;const n=Array.isArray(e.permissions)?e.permissions:[];return({view:["sr_inventory_delivery_notes.view"],update:["sr_inventory_delivery_notes.update"],delete:["sr_inventory_delivery_notes.delete"]}[t]||[]).some(r=>n.includes(r))}function x(e=""){return String(e??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function V({logoUrl:e="",companyName:t="Shadow Group Co., Ltd.",companyPhone:n="",companyEmail:a="",slipCode:r="",receiverName:v="—",receiverPhone:u="—",receiverPlace:c="",deliveryDateText:w="—",itemCount:i=0,totalQtyText:l="0",slipNote:f="",itemRowsHtml:g=""}={}){const d=[n?`<div class="contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg><span>${x(n)}</span></div>`:"",a?`<div class="contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><span>${x(a)}</span></div>`:""].filter(Boolean).join("");return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${x(r||"Delivery Note")}</title>
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
        <div class="logo">${e?`<img id="delivery-slip-logo" src="${x(e)}" alt="Logo">`:""}</div>
        <div class="brand-text">
          <h1>${x(t||"Shadow Group Co., Ltd.")}</h1>
          ${d?`<div class="contacts">${d}</div>`:""}
        </div>
      </div>
      <div class="slip-box">
        <span>SLIP CODE</span>
        <strong>${x(r)}</strong>
      </div>
    </div>
    <hr class="rule">
    <div class="meta">
      <div>
        <div class="meta-row"><span class="label">Receiver</span><span class="colon">:</span><span class="value">${x(v)}</span></div>
        <div class="meta-row"><span class="label">Phone</span><span class="colon">:</span><span class="value">${x(u)}</span></div>
        <div class="meta-row"><span class="label">Address</span><span class="colon">:</span><span class="value">${x(c||"—")}</span></div>
      </div>
      <div>
        <div class="meta-row"><span class="label">Delivery Date</span><span class="colon">:</span><span class="value">${x(w)}</span></div>
        <div class="meta-row"><span class="label">Items</span><span class="colon">:</span><span class="value">${x(String(i))}</span></div>
        <div class="meta-row"><span class="label">Total Qty</span><span class="colon">:</span><span class="value">${x(l)}</span></div>
      </div>
    </div>
    <hr class="rule">
    <div class="note">
      <span class="label">Note</span><span class="colon">:</span>
      <span class="value">${f?x(f).replace(/\r\n|\n|\r/g,"<br>"):"—"}</span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width:10%;">No.</th>
          <th style="width:70%;">Product</th>
          <th style="width:20%;">Qty</th>
        </tr>
      </thead>
      <tbody>${g}</tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="total-label">TOTAL</td>
          <td class="total-qty">${x(l)}</td>
        </tr>
      </tfoot>
    </table>
    <div class="signs">
      <div class="sign">Delivered By<div class="sign-line"></div></div>
      <div class="sign">Received By<div class="sign-line"></div></div>
    </div>
  </div>
</body>
</html>`}export{B as I,W as a,G as b,T as c,P as d,q as e,U as f,F as g,K as h,R as i,Y as j,O as k,V as l,H as m,z as n,Q as s};
