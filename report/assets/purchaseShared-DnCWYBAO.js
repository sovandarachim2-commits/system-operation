import{f as _,j as e,r as k,X as b,e as q}from"./index-BJB1_hX4.js";import{C as K}from"./circle-check-xHOhfDOh.js";import{I as O}from"./image-plus-9CieL2UQ.js";import{T as H}from"./triangle-alert-DI9HiZAd.js";const Y=_("LockOpen",[["rect",{width:"18",height:"11",x:"3",y:"11",rx:"2",ry:"2",key:"1w4ew1"}],["path",{d:"M7 11V7a5 5 0 0 1 9.9-1",key:"1mm8w8"}]]);const T=_("Paperclip",[["path",{d:"M13.234 20.252 21 12.3",key:"1cbrk9"}],["path",{d:"m16 6-8.414 8.586a2 2 0 0 0 0 2.828 2 2 0 0 0 2.828 0l8.414-8.586a4 4 0 0 0 0-5.656 4 4 0 0 0-5.656 0l-8.415 8.585a6 6 0 1 0 8.486 8.486",key:"1pkts6"}]]);const W=_("Upload",[["path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4",key:"ih7n3h"}],["polyline",{points:"17 8 12 3 7 8",key:"t8dd8p"}],["line",{x1:"12",x2:"12",y1:"3",y2:"15",key:"widbto"}]]),G=new Intl.NumberFormat("en-US",{style:"currency",currency:"USD"}),se=new Intl.NumberFormat("en-US"),V={draft:"Draft",sent:"Sent",confirmed:"Confirmed",partial:"Partial",received:"Received",cancelled:"Cancelled",unpaid:"Unpaid",paid:"Paid",completed:"Completed",active:"Active",inactive:"Inactive",pending:"Pending"};function N(t=new Date){const n=t.getFullYear(),a=String(t.getMonth()+1).padStart(2,"0"),r=String(t.getDate()).padStart(2,"0");return`${n}-${a}-${r}`}function ie(t=new Date){return`${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,"0")}-01`}function re(t=new Date){const n=new Date(t.getFullYear(),t.getMonth(),1),a=new Date(t.getFullYear(),t.getMonth()+1,0);return{from:N(n),to:N(a)}}function oe(t=new Date){const n=new Date(t.getFullYear(),t.getMonth()-1,1),a=new Date(t.getFullYear(),t.getMonth(),0);return{from:N(n),to:N(a)}}function Q(t){const n=String(t||"").toLowerCase();return V[n]||(n?n.replace(/_/g," "):"—")}function le(t){return{pin_set:!!t?.pin_set,unlocked:!!t?.unlocked}}function ce(t,n=!0){return n?G.format(Number(t||0)):""}function de({pin:t,error:n,busy:a,title:r="Enter PIN",hint:i="Unlock Unit Cost and Line Total columns.",onChange:d,onClose:u,onSubmit:l}){return e.jsx("div",{className:"inventory-column-picker-backdrop",role:"presentation",children:e.jsxs("div",{className:"inventory-column-picker inventory-pin-unlock",role:"dialog","aria-modal":"true","aria-labelledby":"purchase-pin-unlock-title",children:[e.jsxs("header",{children:[e.jsxs("div",{children:[e.jsx("h3",{id:"purchase-pin-unlock-title",children:r}),e.jsx("span",{children:i})]}),e.jsx("button",{type:"button",className:"inventory-column-picker-close",onClick:u,"aria-label":"Close",children:"×"})]}),e.jsxs("form",{onSubmit:l,children:[e.jsxs("label",{className:"inventory-pin-unlock-field",children:[e.jsx("span",{children:"PIN Code"}),e.jsx("input",{type:"password",inputMode:"numeric",autoComplete:"off",maxLength:8,value:t,onChange:m=>d(m.target.value.replace(/\D/g,"").slice(0,8)),autoFocus:!0})]}),n?e.jsx("div",{className:"inventory-pin-unlock-error",children:n}):null,e.jsxs("footer",{children:[e.jsx("button",{type:"button",className:"muted",onClick:u,disabled:a,children:"Cancel"}),e.jsxs("button",{type:"submit",disabled:a||t.length<4,children:[e.jsx(Y,{size:15}),"Unlock"]})]})]})]})})}function pe({title:t,subtitle:n,actions:a,children:r,message:i,error:d,onClearMessage:u,onClearError:l,className:m=""}){return k.useEffect(()=>{if(!i||!u)return;const p=window.setTimeout(u,3200);return()=>window.clearTimeout(p)},[i,u]),e.jsxs("div",{className:`purchase-page ${m}`.trim(),children:[e.jsxs("header",{className:"purchase-page-header",children:[e.jsxs("div",{className:"purchase-page-header-text",children:[e.jsx("h2",{children:t}),n?e.jsx("p",{children:n}):null]}),a?e.jsx("div",{className:"purchase-page-actions",children:a}):null]}),d?e.jsxs("div",{className:"purchase-alert purchase-alert-error",role:"alert",children:[e.jsx("span",{children:d}),l?e.jsx("button",{type:"button","aria-label":"Dismiss",onClick:l,children:e.jsx(b,{size:16})}):null]}):null,i?e.jsxs("div",{className:"purchase-alert purchase-alert-success",role:"status",children:[e.jsx(K,{size:16}),e.jsx("span",{children:i})]}):null,r]})}function ue({title:t="Cannot complete",message:n,onClose:a}){return n?e.jsx("div",{className:"purchase-alert-overlay",role:"alertdialog","aria-modal":"true","aria-labelledby":"purchase-alert-title",onClick:a,children:e.jsxs("div",{className:"purchase-alert-card",onClick:r=>r.stopPropagation(),children:[e.jsx("span",{className:"purchase-alert-card-icon","aria-hidden":"true",children:e.jsx(H,{size:18})}),e.jsxs("div",{children:[e.jsx("strong",{id:"purchase-alert-title",children:t}),e.jsx("p",{children:n})]}),e.jsx("button",{type:"button",onClick:a,children:"OK"})]})}):null}function me({value:t,label:n}){const a=String(t||"unknown").toLowerCase();return e.jsx("span",{className:`purchase-status purchase-status-${a}`,children:n||Q(a)})}function he({cols:t,text:n,action:a}){return e.jsx("tr",{children:e.jsx("td",{colSpan:t,className:"table-state",children:e.jsxs("div",{className:"purchase-empty",children:[e.jsx("p",{children:n}),a||null]})})})}function ge({children:t,onSubmit:n,className:a=""}){return e.jsx("form",{className:`purchase-toolbar ${a}`.trim(),onSubmit:r=>{r.preventDefault(),n?.()},children:t})}function ve({value:t,onChange:n,placeholder:a="Search...",onSearch:r}){return e.jsxs("label",{className:"purchase-search",children:[e.jsx(q,{size:16,"aria-hidden":"true"}),e.jsx("input",{value:t,onChange:i=>n(i.target.value),placeholder:a,onKeyDown:i=>{i.key==="Enter"&&(i.preventDefault(),r?.())}})]})}function xe({title:t,children:n,onClose:a,footer:r,headerActions:i=null,wide:d=!1,compact:u=!1,className:l="",closeOnBackdrop:m=!0}){return k.useEffect(()=>{function p(g){g.key==="Escape"&&a?.()}return window.addEventListener("keydown",p),()=>window.removeEventListener("keydown",p)},[a]),e.jsx("div",{className:"purchase-modal-backdrop",role:"presentation",onClick:m?a:void 0,children:e.jsxs("div",{className:`purchase-modal${d?" wide":""}${u?" compact":""}${l?` ${l}`:""}`,role:"dialog","aria-modal":"true","aria-label":t,onClick:p=>p.stopPropagation(),children:[e.jsxs("header",{children:[e.jsx("strong",{children:t}),i?e.jsx("div",{className:"purchase-modal-header-actions",children:i}):null,e.jsx("button",{type:"button",className:"purchase-icon-btn","aria-label":"Close",onClick:a,children:e.jsx(b,{size:18})})]}),e.jsx("div",{className:"purchase-modal-body",children:n}),r?e.jsx("footer",{children:r}):null]})})}function fe({label:t,children:n,wide:a=!1,hint:r,required:i=!1,className:d=""}){return e.jsxs("label",{className:[a?"wide":"",d].filter(Boolean).join(" ")||void 0,children:[e.jsxs("span",{children:[t,i?e.jsx("b",{className:"purchase-field-required",children:"*"}):null]}),n,r?e.jsx("em",{className:"purchase-field-hint",children:r}):null]})}function be({label:t="Reference images",files:n=[],onFilesChange:a,existingFiles:r=[],existingFileUrls:i=[],removedFiles:d=[],onRemovedFilesChange:u,readOnly:l=!1}){const m=r.map((s,h)=>({path:String(s||""),url:String(i[h]||"")})).filter(s=>s.path&&!d.includes(s.path));function p(s){const h=Array.from(s||[]);h.length&&a?.([...n,...h])}function g(s){a?.(n.filter((h,x)=>x!==s))}function o(s){u?.([...d,s.path])}return e.jsxs("div",{className:"purchase-file-picker",children:[e.jsxs("div",{className:"purchase-file-picker-head",children:[e.jsx("span",{children:t}),l?null:e.jsxs("label",{className:"purchase-file-add",children:[e.jsx(W,{size:15}),e.jsx("input",{type:"file",accept:"image/*",multiple:!0,onChange:s=>{p(s.target.files),s.target.value=""}}),"Add images"]})]}),m.length||n.length?e.jsxs("div",{className:l?"purchase-file-list purchase-file-preview-list":"purchase-file-list",children:[m.map(s=>l?e.jsxs("a",{className:"purchase-file-preview",href:P(s),target:"_blank",rel:"noreferrer",children:[e.jsx("img",{src:P(s),alt:w(s),loading:"lazy"}),e.jsx("span",{children:w(s)})]},`existing-${s.path}`):e.jsxs("span",{children:[e.jsx(T,{size:13}),e.jsx("a",{href:P(s),target:"_blank",rel:"noreferrer",children:w(s)}),e.jsx("button",{type:"button",onClick:()=>o(s),"aria-label":`Remove ${w(s)}`,children:e.jsx(b,{size:13})})]},`existing-${s.path}`)),n.map((s,h)=>e.jsxs("span",{children:[e.jsx(T,{size:13}),s.name,l?null:e.jsx("button",{type:"button",onClick:()=>g(h),"aria-label":`Remove ${s.name}`,children:e.jsx(b,{size:13})})]},`${s.name}-${s.size}-${h}`))]}):e.jsx("small",{className:"purchase-file-empty",children:l?"No images attached.":"No images selected."})]})}function ye({label:t="Images",files:n=[],onFilesChange:a,maxFiles:r=6,readOnly:i=!1,existingImages:d=[],onRemoveExisting:u}){const l=k.useMemo(()=>(n||[]).map((o,s)=>({key:`${o.name}-${o.size}-${o.lastModified}-${s}`,url:URL.createObjectURL(o),index:s})),[n]);k.useEffect(()=>()=>{l.forEach(o=>URL.revokeObjectURL(o.url))},[l]);function m(o){const s=Array.from(o.target.files||[]).filter(y=>y.type.startsWith("image/"));if(!s.length)return;const h=(n?.length||0)+(d?.length||0),x=Math.max(0,r-h);x&&(a?.([...n||[],...s.slice(0,x)]),o.target.value="")}const p=(n?.length||0)+(d?.length||0),g=!i&&p<r;return e.jsxs("div",{className:"purchase-image-field",children:[e.jsx("span",{children:t}),e.jsxs("div",{className:"marketing-image-picker purchase-image-picker",children:[(d||[]).map(o=>e.jsxs("div",{className:"marketing-image-preview",children:[e.jsx("img",{src:o.url,alt:""}),!i&&u?e.jsx("button",{type:"button",onClick:()=>u(o.path),"aria-label":"Remove image",children:e.jsx(b,{size:14})}):null]},o.path||o.url)),l.map(o=>e.jsxs("div",{className:"marketing-image-preview",children:[e.jsx("img",{src:o.url,alt:""}),i?null:e.jsx("button",{type:"button",onClick:()=>a?.((n||[]).filter((s,h)=>h!==o.index)),"aria-label":"Remove image",children:e.jsx(b,{size:14})})]},o.key)),g?e.jsxs("label",{className:"marketing-image-add purchase-image-add",children:[e.jsx("input",{type:"file",accept:"image/*",multiple:!0,onChange:m}),e.jsx(O,{size:18}),e.jsx("em",{children:"Add image"})]}):null,!p&&i?e.jsx("small",{className:"purchase-image-empty",children:"No images attached."}):null]})]})}function X(t){const n=String(t).toLowerCase();return n.includes("unpaid")?"unpaid":n.includes("aba")||n.includes("bank")||n.includes("transfer")?"aba":n==="ac"||n.includes("ac ")?"ac":n.includes("cash")?"cash":(n.includes("cheque")||n.includes("check")||n.includes("card"),"other")}function je({value:t,label:n}){const a=n||String(t||"").replace(/_/g," ")||"—";return e.jsx("span",{className:`payment-method-badge ${X(a)}`,children:a})}function we(t){return new Promise((n,a)=>{const r=new FileReader;r.onload=()=>{const i=String(r.result||""),d=i.includes(",")?i.split(",").pop():i;n({name:t.name,type:t.type||"application/octet-stream",size:t.size,data:d})},r.onerror=()=>a(new Error(`Unable to read ${t.name}`)),r.readAsDataURL(t)})}function w(t){const n=typeof t=="object"&&t!==null?t.path||t.url||"":t;return String(n||"").split(/[\\/]/).pop()||"Attachment"}function P(t){const n=String((typeof t=="object"&&t!==null?t.url||t.path:t)||"");return/^https?:\/\//i.test(n)||n.startsWith("/")?n:n.startsWith("uploads/")?`/OrderShadow/${n}`:`/OrderShadow/uploads/${n}`}function c(t=""){return String(t??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function J(t={}){const n=t.settings||t.invoice_settings||{},a=n.company_name||"Shadow Group Co., Ltd.",r=n.company_phone||"",i=n.company_email||"",d=n.company_address||"",u=n.contact_person||"",l=t.logo_url||t.invoice_logo_url||n.logo_url||"",m=l?`${l}${l.includes("?")?"&":"?"}v=${Date.now()}`:"",p=Math.max(40,Math.min(200,Number(n.logo_width||80))),g=Math.max(40,Math.min(200,Number(n.logo_height||70))),o=t.order||{},s=t.payment||{},h=t.items||[],x=o.order_number||s.payment_number||`PO-${o.id||""}`,y=t.invoice_number||x,A=h.reduce((v,f)=>v+Number(f.quantity_ordered||f.quantity||0)*Number(f.unit_price||f.unit_cost||0),0),$=Number(o.total_amount||A||0),D=Number(o.total_paid||s.payment_amount||0),F=Number(o.balance_amount||Math.max(0,$-D)),z=t.orderDate||o.order_date||"",j=z?new Date(z.replace(" ","T")):null,L=j?j.toLocaleDateString("km-KH",{day:"numeric",month:"long",year:"numeric"}):"—",S=j?j.toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"}):"—",I=o.vendor_name||t.vendor_name||"—",R=h.length?h.map((v,f)=>{const U=Number(v.quantity_ordered||v.quantity||0),M=Number(v.unit_price||v.unit_cost||0);return`<tr>
      <td class="tc">${f+1}</td>
      <td><strong>${c(v.item_name||v.product_name||"-")}</strong>${v.sku?`<div class="sku">${c(v.sku)}</div>`:""}</td>
      <td class="tc">${c(String(U))}</td>
      <td class="tc">${c(Number(M).toFixed(2))}</td>
      <td class="tc">${c(String(U*M))}</td>
    </tr>`}).join(""):'<tr><td colspan="5" class="tc">No items</td></tr>',C=[u?`<div><span class="lbl">Contact:</span> <span class="val">${c(u)}</span></div>`:"",d?`<div><span class="lbl">Address:</span> <span class="val">${c(d).replace(/\n/g,"<br>")}</span></div>`:"",r?`<div><span class="lbl">Phone:</span> <span class="val">${c(r)}</span></div>`:"",i?`<div><span class="lbl">Email:</span> <span class="val">${c(i)}</span></div>`:""].filter(Boolean).join(""),B="វិក្កយបត្រការបញ្ជាទិញ",E="SUPPLIER INVOICE";return`<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <title>${c(y)} ${E}</title>
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@400;700&amp;family=Noto+Sans+Khmer:wght@400;600;700;800&amp;display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #e5e7eb; color: #111827; font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", Arial, system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .invoice-page { max-width: 820px; margin: 1.5rem auto; background: #fff; box-shadow: 0 4px 24px rgba(0,0,0,.13); overflow: hidden; padding: 32px 40px; }
    .inv-accent { background: #1e3a5f; height: 7px; margin: -32px -40px 24px; }
    .inv-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 18px; }
    .inv-top-left { flex: 1; min-width: 0; }
    .inv-top-right { flex: 0 0 46%; text-align: right; }
    .inv-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
    .logo { line-height: 1; }
    .logo img { max-width: ${p}px; max-height: ${g}px; object-fit: contain; display: block; }
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
    .inv-items th { background: #1e3a5f; color: #fff; padding: 9px 12px; text-align: center; font-size: 12px; font-weight: 700; border: 1px solid #16304d; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-items th .th-en { display: block; font-size: 10px; font-weight: 600; letter-spacing: 0.3px; opacity: 0.92; }
    .inv-items th.tl { text-align: left; }
    .inv-items td { padding: 8px 12px; border: 1px solid #000; font-size: 13px; color: #111; }
    .inv-items td.tc, .tc { text-align: center; }
    .inv-items tbody tr:nth-child(even) { background: #f3f6fa; }
    .inv-items .sku { margin-top: 2px; color: #4b5563; font-size: 11px; font-weight: 500; }
    .inv-items tfoot td { background: #e8eef5; font-weight: 800; }
    .inv-notes { min-height: 44px; padding: 10px 0 4px; font-size: 13px; color: #111; }
    .inv-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 16px 0; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
    .inv-summary-item { display: flex; justify-content: space-between; font-size: 14px; }
    .inv-summary-item span:first-child { color: #6b7280; }
    .inv-summary-item strong { color: #111; }
    .inv-summary-item.total { border-top: 2px solid #1e3a5f; padding-top: 8px; margin-top: 4px; }
    .inv-signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 28px; margin-top: 56px; padding: 0 8px; }
    .inv-sig { text-align: center; font-size: 14px; font-weight: 800; color: #111; }
    .inv-sig .sig-en { display: block; font-size: 12px; font-weight: 700; margin-top: 2px; }
    .inv-sig-line { border-top: 1px solid #111; margin-top: 64px; }
    .inv-generated { margin-top: 16px; text-align: center; font-size: 11px; color: #111; padding-top: 8px; }
    @page { size: A4; margin: 10mm; }
    @media print { html, body { background: #fff; } .invoice-page { max-width: none; margin: 0; padding: 8px 12px; box-shadow: none; } .inv-accent { margin: -8px -12px 8px; } }
  </style>
</head>
<body>
  <section class="invoice-page">
    <div class="inv-accent"></div>
    <div class="inv-top">
      <div class="inv-top-left">
        <div class="inv-brand">
          <div class="logo">${m?`<img id="supplier-invoice-logo" src="${c(m)}" alt="Logo">`:""}</div>
          <div class="inv-company">
            <div class="inv-company-name">${c(a)}</div>
            ${C?`<div class="inv-company-info">${C}</div>`:""}
          </div>
        </div>
      </div>
      <div class="inv-top-right">
        <div class="inv-title-km">${B}</div>
        <div class="inv-title-en">${E}</div>
        <table class="inv-info-tbl">
          <tr><td><span class="lbl-km">លេខវិក្កយបត្រ</span><span class="lbl-en">Invoice No</span></td><td>${c(y)}</td></tr>
          <tr><td><span class="lbl-km">កាលបរិច្ឆេទ</span><span class="lbl-en">Date</span></td><td>${L===S?c(S):`${c(L)} / ${c(S)}`}</td></tr>
          <tr><td><span class="lbl-km">អ្នកផ្គត់ផ្គង់</span><span class="lbl-en">Supplier</span></td><td>${c(I)}</td></tr>
          <tr><td><span class="lbl-km">ការបញ្ជាទិញ</span><span class="lbl-en">Order</span></td><td>${c(x)}</td></tr>
        </table>
      </div>
    </div>
    <hr class="inv-divider">
    <table class="inv-items">
      <thead>
        <tr>
          <th class="tl" style="width:8%;">ល.រ<span class="th-en">No</span></th>
          <th class="tl">ផលិតផល<span class="th-en">Product</span></th>
          <th style="width:16%;">ចំនួន<span class="th-en">Qty</span></th>
          <th style="width:16%;">តម្លៃ<span class="th-en">Price</span></th>
          <th style="width:16%;">សរុប<span class="th-en">Total</span></th>
        </tr>
      </thead>
      <tbody>${R}</tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="tc">សរុប / TOTAL</td>
          <td class="tc">${c(Number($).toFixed(2))}</td>
        </tr>
      </tfoot>
    </table>
    <div class="inv-summary">
      <div class="inv-summary-item"><span>Order Total</span><strong>${c(Number($).toFixed(2))}</strong></div>
      <div class="inv-summary-item"><span>Total Paid</span><strong>${c(Number(D).toFixed(2))}</strong></div>
      <div class="inv-summary-item"><span>Balance Due</span><strong>${c(Number(F).toFixed(2))}</strong></div>
      <div class="inv-summary-item"><span>Payment Method</span><strong>${c(s.payment_method||"—")}</strong></div>
    </div>
    ${s.reference_number?`<div class="inv-notes"><span class="lbl">Reference:</span> ${c(s.reference_number)}</div>`:""}
    ${s.notes?`<div class="inv-notes">${c(s.notes)}</div>`:""}
    <div class="inv-signatures">
      <div class="inv-sig">រៀបចំ<span class="sig-en">Prepare</span><div class="inv-sig-line"></div></div>
      <div class="inv-sig">អនុម័ត<span class="sig-en">Approve</span><div class="inv-sig-line"></div></div>
      <div class="inv-sig">អ្នកទទួល<span class="sig-en">Receiver</span><div class="inv-sig-line"></div></div>
    </div>
    <div class="inv-generated">បង្កើតនៅ ${c(new Date().toLocaleString("km-KH"))} / Generated ${c(new Date().toLocaleString())} - សូមអរគុណ! / Thank you!</div>
  </section>
</body>
</html>`}function Z(t,n){const a=document.createElement("iframe");a.style.cssText="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;",document.body.appendChild(a);const r=a.contentWindow,i=r?.document||a.contentDocument;if(!i){a.remove(),window.alert("Unable to open print window.");return}i.open(),i.write(t),i.close();const d=()=>{try{r?.focus?.(),r?.print?.()}catch(l){console.error("Print failed:",l)}window.setTimeout(()=>{try{a.remove()}catch{}},800)},u=()=>{const l=[...i.images||[]],m=i.getElementById(n);return m&&!l.includes(m)&&l.push(m),!l.length||l.every(p=>p.complete)?Promise.resolve():Promise.all(l.map(p=>new Promise(g=>{if(p.complete)return g();const o=()=>g();p.addEventListener("load",o,{once:!0}),p.addEventListener("error",o,{once:!0}),window.setTimeout(o,1500)})))};Promise.resolve().then(()=>i.fonts?.ready).catch(()=>{}).then(u).then(()=>window.setTimeout(d,80))}function ke(t){Z(J(t||{}),"supplier-invoice-logo")}export{he as E,fe as F,Y as L,pe as P,ve as S,W as U,ge as a,de as b,me as c,xe as d,be as e,we as f,re as g,le as h,N as i,ce as j,ye as k,oe as l,G as m,se as n,ue as o,je as p,ke as q,ie as r,Q as s,T as t};
