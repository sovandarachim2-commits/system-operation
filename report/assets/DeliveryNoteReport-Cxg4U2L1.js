import{r as l,j as e,t as Me,E as Qe,c as he,u as pe,b as Ue,a as ye,l as ue,A as ve}from"./index-BOHyaEv4.js";import{j as fe,b as He,f as ge,k as Ge,l as Ve}from"./inventoryShared-CvOz-wcY.js";import{q as We,i as X,P as Ye,n as b,E as Je,c as xe,F as E}from"./purchaseShared-BaxQeVfq.js";import{P as be}from"./pencil-Mr_hgw2w.js";import{T as Xe}from"./trash-2-BeqN9CnM.js";import{S as Ze}from"./save-BSE6M3fK.js";import"./index-UFiCzsma.js";import"./circle-check-BBri40Kb.js";import"./upload-BqQgOmn1.js";import"./lock-open-BaUxub-V.js";import"./image-plus-BtEtsrDp.js";import"./triangle-alert-CElZ-UEr.js";const et={receiver_name:"",phone_number:"",address:"",delivery_date:"",note:""};function s(n=""){return String(n||"").replace(/\s+/g," ").trim()}function h(n=""){return String(n??"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function tt(n){return s(n.slip_code)}function I(n){const i=String(n||"").trim();if(/^\d{4}-\d{2}-\d{2}/.test(i))return i.slice(0,10);const c=i?new Date(i.replace(" ","T")):null;return c&&!Number.isNaN(c.getTime())?X(c):""}function J(n){const i=I(n);if(!i)return"—";const c=new Date(`${i}T00:00:00`);return Number.isNaN(c.getTime())?i:c.toLocaleDateString("en-GB",{day:"2-digit",month:"short",year:"numeric"})}function je(n){const i=String(n||"").trim();if(!i)return"";const c=i.indexOf("Delivery:");if(c<0)return i;const N=i.slice(0,c).replace(/\|\|\s*$/,"").trim();let j="";return i.slice(c+9).split("|").forEach(M=>{const _=M.trim().match(/^([^:]+):\s*(.*)$/);_&&_[1].trim().toLowerCase()==="note"&&(j=_[2].trim())}),[N,j].filter(Boolean).join(`
`)}function _e(n){const i=je(n?.note||n?.notes||n?.remark||"");if(i)return i;const c=(Array.isArray(n?.items)?n.items:[]).map(N=>je(N?.note||N?.notes||"")).filter(Boolean);return Array.from(new Set(c)).join(`
`)}function k(n){return _e(n).replace(/\s+/g," ").trim()}function rt(n){const i=_e(n);return i?h(i).replace(/\r\n|\n|\r/g,"<br>"):""}function at(n){return I(n?.delivery_date||n?.created_at)}function ft({user:n}){const i=fe(n,"update"),c=fe(n,"delete"),[N,j]=l.useState(""),[M,_]=l.useState(""),[y,T]=l.useState([]),[K,Z]=l.useState(!0),[o,P]=l.useState(null),[v,$]=l.useState(null),[u,ee]=l.useState(et),[te,w]=l.useState(""),[f,re]=l.useState(!1),[Q,we]=l.useState(""),[z,Ne]=l.useState(""),[R,Se]=l.useState(""),[S,De]=l.useState(()=>We()),[D,Ce]=l.useState(()=>X()),[ae,ne]=l.useState(""),[U,se]=l.useState({company_name:"Shadow Group Co., Ltd.",company_phone:"",company_email:""}),Be=l.useMemo(()=>{const t=new Map;return y.forEach(r=>{const a=s(r.movement_type_label)||"Transfer";t.has(a)||t.set(a,a)}),Array.from(t.values()).sort((r,a)=>r.localeCompare(a))},[y]),ke=l.useMemo(()=>{const t=new Map;return y.forEach(r=>{const a=s(r.created_by_name);a&&(t.has(a)||t.set(a,a))}),Array.from(t.values()).sort((r,a)=>r.localeCompare(a))},[y]),C=l.useMemo(()=>{const t=s(Q).toLowerCase();return y.filter(r=>{const a=s(r.movement_type_label)||"Transfer",m=at(r);return z&&a!==z||R&&s(r.created_by_name)!==R||S&&m&&m<S||D&&m&&m>D?!1:t?[r.slip_code,r.receiver_name,r.receiver_phone,r.delivery_date,r.transfer_to,r.note,k(r),r.location_label,r.movement_type_label,r.created_by_name].join(" ").toLowerCase().includes(t):!0})},[y,Q,z,R,S,D]),H=l.useMemo(()=>({count:C.length,qty:C.reduce((t,r)=>t+Number(r.total_qty||0),0)}),[C]);async function ie(){Z(!0),j("");try{const t=await ye("/api/inventory_ops.php",{action:"delivery_slip_history",from:S,to:D});T(t.delivery_slips||[]),s(t.logo_url)&&ne(s(t.logo_url)),se({company_name:s(t.company_name)||"Shadow Group Co., Ltd.",company_phone:s(t.company_phone),company_email:s(t.company_email)})}catch(t){j(t.message||"Unable to load delivery slip history."),T([])}finally{Z(!1)}}l.useEffect(()=>{ie()},[S,D]);async function Pe(t){if(!c)return;const r=s(t?.slip_code);if(r&&window.confirm(`Delete delivery note ${r}?`)){j("");try{await ue("/api/inventory_ops.php",{action:"delete_delivery_slip_history",slip_code:r}),T(a=>a.filter(m=>m.slip_code!==r)),o?.slip_code===r&&P(null),v?.slip_code===r&&$(null),_(`Delivery note ${r} deleted.`)}catch(a){j(a.message||"Unable to delete delivery note.")}}}function $e(t){P(t)}function oe(t){i&&(w(""),$(t),ee({receiver_name:t.receiver_name||"",phone_number:t.receiver_phone||"",address:t.transfer_to||"",delivery_date:I(t.delivery_date||t.created_at)||X(),note:k(t)}))}function q(t,r){ee(a=>({...a,[t]:r}))}async function qe(){if(!v?.slip_code)return;const t={receiver_name:s(u.receiver_name),phone_number:s(u.phone_number),address:s(u.address),delivery_date:I(u.delivery_date),note:s(u.note)};if(!t.receiver_name){w("Receiver name is required.");return}if(!t.phone_number){w("Phone number is required.");return}if(!t.address){w("Address is required.");return}if(!t.delivery_date){w("Delivery date is required.");return}const r={...v,receiver_name:t.receiver_name,receiver_phone:t.phone_number,transfer_to:t.address,delivery_date:t.delivery_date,note:t.note};r.qr_payload=tt(r),re(!0),w("");try{const m=(await ue("/api/inventory_ops.php",{action:"update_delivery_slip_history",slip_code:v.slip_code,receiver_name:t.receiver_name,receiver_phone:t.phone_number,transfer_to:t.address,delivery_date:t.delivery_date,note:t.note,qr_payload:r.qr_payload})).delivery_slip||r;T(g=>g.map(B=>B.slip_code===m.slip_code?{...B,...m}:B)),o?.slip_code===m.slip_code&&P(g=>({...g,...m})),$(null),_(`Delivery note ${m.slip_code||v.slip_code} updated.`)}catch(a){w(a.message||"Unable to update delivery note.")}finally{re(!1)}}async function Ee(){if(ae)return ae;try{const t=await ye("/api/inventory_ops.php",{action:"default_logo"}),r=s(t.logo_url)||`${ve}/public/image.png`;return ne(r),se({company_name:s(t.company_name)||"Shadow Group Co., Ltd.",company_phone:s(t.company_phone),company_email:s(t.company_email)}),r}catch{return`${ve}/public/image.png`}}async function A(t,{savePdf:r=!1}={}){if(!t)return;const a=Array.isArray(t.items)?t.items:[],m=Number(t.total_qty||a.reduce((d,p)=>d+Number(p.qty||0),0)),g=s(t.slip_code);if(!g){window.alert("Delivery slip code is missing.");return}const B=s(t.receiver_name)||"—",le=s(t.receiver_phone)||"—",G=s(t.transfer_to),V=J(t.delivery_date||t.created_at),Te=k(t),de=rt(t),W=t.created_at?new Date(String(t.created_at).replace(" ","T")):null,Ke=W&&!Number.isNaN(W.getTime())?W.toLocaleString():t.created_at||new Date().toLocaleString(),ze=s(t.slip_code);let Re=`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(ze)}`,ce=await Ee();const L=d=>{const p=Number(d||0);return Number.isFinite(p)?Number.isInteger(p)?String(p):String(Math.round(p*100)/100):"0"},Ae=a.map((d,p)=>`
        <tr>
            <td class="tc">${p+1}</td>
            <td class="tl">${h(d.product||"—")}</td>
            <td class="tc">${h(L(d.qty))}</td>
        </tr>
    `).join(""),Le=a.map((d,p)=>`
        <tr>
            <td class="no">${p+1}</td>
            <td class="product">${h(d.product||"—")}</td>
            <td class="qty">${h(L(d.qty))}</td>
        </tr>
    `).join(""),Oe=r?Ve({logoUrl:ce,companyName:U.company_name,companyPhone:U.company_phone,companyEmail:U.company_email,slipCode:g,receiverName:B,receiverPhone:le,receiverPlace:G,deliveryDateText:V,itemCount:a.length||Number(t.item_count||0),totalQtyText:L(m),slipNote:Te,itemRowsHtml:Le}):`<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${h(t.slip_title||"TRANSFER SLIP")}</title>
    <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@400;700&family=Noto+Sans+Khmer:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 12px;
            background: #e5e7eb;
            color: #000;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
            font-weight: 400;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }
        body.pdf-mode {
            padding: 0;
            background: #fff;
        }
        .slip {
            width: 360px;
            max-width: 360px;
            margin: 0 auto;
            background: #fff;
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(15,23,42,.18);
        }
        .logo { text-align: center; margin-bottom: 8px; min-height: 28px; }
        .logo img { max-width: 28mm; max-height: 18mm; object-fit: contain; }
        .title {
            text-align: center;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: .08em;
            margin: 0 0 12px;
            line-height: 1.3;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .meta {
            flex: 1;
            min-width: 0;
            font-size: 18px;
            line-height: 1.35;
            font-weight: 600;
            overflow-wrap: anywhere;
            word-break: break-word;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .meta-row { margin-bottom: 4px; }
        .meta-label,
        .meta-value {
            font-weight: 600;
            font-size: 18px;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .qr-wrap { flex: 0 0 22mm; width: 22mm; text-align: center; }
        .qr { width: 22mm; height: 22mm; object-fit: contain; display: block; margin: 0 auto; }
        .qr-text {
            margin-top: 4px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            overflow-wrap: anywhere;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .qr-date {
            margin-top: 2px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .divider {
            border: 0;
            border-top: 1px solid #000;
            margin: 10px 0 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
            font-weight: 700;
        }
        th, td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 13px;
            vertical-align: middle;
            line-height: 1.25;
            overflow-wrap: anywhere;
            color: #111;
            font-weight: 700;
        }
        th {
            background: #d1d5db;
            text-align: center;
            font-weight: 800;
            line-height: 1.2;
        }
        th span {
            display: block;
            font-size: 11px;
            font-weight: 600;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
            letter-spacing: .01em;
        }
        td.tl { text-align: left; font-weight: 700; }
        .tc { text-align: center; font-weight: 700; }
        tfoot td { background: #fff; font-weight: 800; }
        .total-label {
            text-align: right;
            font-size: 13px;
            font-weight: 800;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .total-value {
            text-align: center;
            font-size: 15px;
            font-weight: 900;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .created-text {
            color: #000;
            font-weight: 600;
            font-size: 14px;
            margin-top: 8px;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #000;
            margin: 0 0 4px;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .khmer-text {
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .note-text {
            color: #666;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.45;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }
        .receipt-powered-by {
            margin-top: 0.65rem;
            color: #111;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-align: center;
            font-family: "Khmer OS Battambang", "Battambang", "Noto Sans Khmer", system-ui, sans-serif;
        }
        .thermal-cut-feed { display: none; }
        @media print {
            @page { size: 80mm auto; margin: 0; }
            html, body { width: 80mm; margin: 0; padding: 0; background: #fff; overflow: visible; }
            .slip { width: 80mm; max-width: 80mm; margin: 0; box-shadow: none; border: 0; border-radius: 0; padding: 5mm; overflow: visible; }
            .thermal-cut-feed { display: block; height: 18mm; }
        }
        body.pdf-mode .slip {
            width: 80mm;
            max-width: 80mm;
            margin: 0;
            box-shadow: none;
            border: 0;
            border-radius: 0;
            padding: 5mm;
        }
    </style>
</head>
<body class="${r?"pdf-mode":""}">
    <div class="slip">
        <div class="logo"><img id="delivery-slip-logo" src="${h(ce)}" alt="Logo" onerror="this.parentNode.innerHTML=''"></div>
        <div class="title">ប័ណ្ណស្តុក</div>
        <div class="top">
            <div class="meta">
                <div class="meta-row"><span class="meta-label">អ្នកទទួល:</span> <span class="meta-value">${h(B)}</span></div>
                <div class="meta-row"><span class="meta-label">លេខទូរស័ព្ទ:</span> <span class="meta-value">${h(le)}</span></div>
                ${G?`<div class="meta-row"><span class="meta-label">ទីតាំង:</span> <span class="meta-value">${h(G)}</span></div>`:""}
            </div>
            <div class="qr-wrap">
                <img class="qr" id="delivery-slip-qr" src="${Re}" alt="QR ${h(g)}">
                <div class="qr-text">${h(g)}</div>
                ${V!=="—"?`<div class="qr-date">${h(V)}</div>`:""}
            </div>
        </div>
        <hr class="divider">
        <table>
            <thead>
                <tr>
                    <th style="width:12%;">ល.រ<span>No</span></th>
                    <th style="width:62%;">មុខទំនិញ<span>Product Name</span></th>
                    <th style="width:26%;">ចំនួន<span>Qty</span></th>
                </tr>
            </thead>
            <tbody>${Ae}</tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="total-label">សរុបចំនួន</td>
                    <td class="total-value">${h(L(m))}</td>
                </tr>
            </tfoot>
        </table>
        <div class="created-text">Created: ${h(Ke)}</div>
        ${de?`
        <hr class="divider">
        <div class="section-title">Notes</div>
        <div class="note-text khmer-text">${de}</div>
        `:""}
        <div class="receipt-powered-by">Powered by : One Night Solution</div>
        <div class="thermal-cut-feed" aria-hidden="true"></div>
    </div>
</body>
</html>`;try{const d=document.createElement("iframe");d.style.cssText="position:fixed;left:-10000px;top:0;width:80mm;min-height:400px;border:0;",document.body.appendChild(d);const p=d.contentWindow,x=p?.document||d.contentDocument;if(!x)throw new Error("Unable to open print frame.");x.open(),x.write(Oe),x.close(),d.style.height=`${Math.max(x.documentElement?.scrollHeight||0,800)}px`;const Fe=()=>{try{p?.focus?.(),p?.print?.()}catch(O){console.error("Print failed:",O)}window.setTimeout(()=>{try{document.body.removeChild(d)}catch{}},800)};(async()=>{try{x.fonts?.ready&&await x.fonts.ready}catch{}const O=F=>!F||F.complete?Promise.resolve():new Promise(Ie=>{let me=!1;const Y=()=>{me||(me=!0,Ie())};F.addEventListener("load",Y),F.addEventListener("error",Y),window.setTimeout(Y,1500)});await Promise.all([O(x.getElementById("delivery-slip-logo")),O(x.getElementById("delivery-slip-qr"))])})().then(()=>window.setTimeout(Fe,80))}catch(d){console.error(d),window.alert("Printing failed. Please allow popups and try again.")}}return e.jsxs(Ye,{className:"purchase-clean-page purchase-reports-page inventory-transfer-page inventory-delivery-report-page",title:"Delivery Note Report",subtitle:"Delivery notes created from stock transfers",error:N,message:M,onClearError:()=>j(""),onClearMessage:()=>_(""),actions:e.jsxs("button",{type:"button",className:"muted",onClick:ie,disabled:K,children:[e.jsx(Ue,{size:16})," ",K?"Loading...":"Refresh"]}),children:[e.jsx(He,{title:"Delivery Notes",rowsCount:!K&&y.length>0?`${b.format(C.length)} notes`:"",children:e.jsxs("div",{className:"inventory-delivery-report",children:[e.jsxs("div",{className:"inventory-delivery-note-summary",children:[e.jsxs("div",{children:[e.jsx("span",{children:"Delivery notes"}),e.jsxs("strong",{children:[b.format(H.count),H.count!==y.length?` / ${b.format(y.length)}`:""]})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Total Qty"}),e.jsx("strong",{className:"tone-blue",children:b.format(H.qty)})]})]}),e.jsxs("div",{className:"inventory-delivery-report-filters",children:[e.jsxs("label",{children:[e.jsx("span",{children:"From"}),e.jsx("input",{type:"date",value:S,onChange:t=>De(t.target.value)})]}),e.jsxs("label",{children:[e.jsx("span",{children:"To"}),e.jsx("input",{type:"date",value:D,onChange:t=>Ce(t.target.value)})]}),e.jsxs("label",{children:[e.jsx("span",{children:"Type"}),e.jsxs("select",{value:z,onChange:t=>Ne(t.target.value),children:[e.jsx("option",{value:"",children:"All types"}),Be.map(t=>e.jsx("option",{value:t,children:t},t))]})]}),e.jsxs("label",{children:[e.jsx("span",{children:"Created By"}),e.jsxs("select",{value:R,onChange:t=>Se(t.target.value),children:[e.jsx("option",{value:"",children:"All users"}),ke.map(t=>e.jsx("option",{value:t,children:t},t))]})]}),e.jsxs("label",{className:"inventory-delivery-report-search",children:[e.jsx("span",{children:"Search"}),e.jsxs("div",{children:[e.jsx(Me,{size:14}),e.jsx("input",{type:"search",value:Q,onChange:t=>we(t.target.value),placeholder:"Code, receiver, phone, note..."})]})]})]}),e.jsx("div",{className:"table-wrap",children:e.jsxs("table",{className:"orders-table erp-table inventory-delivery-report-table",children:[e.jsx("thead",{children:e.jsxs("tr",{children:[e.jsx("th",{className:"col-no",children:"No"}),e.jsx("th",{className:"col-slip",children:"Code"}),e.jsx("th",{className:"col-date",children:"Delivery Date"}),e.jsx("th",{className:"col-receiver",children:"Receiver"}),e.jsx("th",{className:"col-phone",children:"Phone"}),e.jsx("th",{className:"col-address",children:"Address"}),e.jsx("th",{className:"col-note",children:"Note"}),e.jsx("th",{className:"col-by",children:"Created By"}),e.jsx("th",{className:"col-actions",children:"Action"})]})}),e.jsxs("tbody",{children:[C.length?null:e.jsx(Je,{cols:9,text:K?"Loading delivery notes...":y.length?"No delivery notes match these filters.":"No delivery slip history yet."}),C.map((t,r)=>e.jsxs("tr",{children:[e.jsx("td",{className:"col-no",children:r+1}),e.jsx("td",{className:"col-slip",children:e.jsx("span",{className:"inventory-delivery-slip-code",title:t.slip_code||"",children:t.slip_code||"—"})}),e.jsx("td",{className:"col-date",children:J(t.delivery_date||t.created_at)}),e.jsx("td",{className:"col-receiver",children:t.receiver_name||"—"}),e.jsx("td",{className:"col-phone",children:t.receiver_phone||"—"}),e.jsx("td",{className:"col-address inventory-delivery-location-cell",children:t.transfer_to||"—"}),e.jsx("td",{className:"col-note",title:k(t)||"",children:k(t)||"—"}),e.jsxs("td",{className:"col-by inventory-delivery-created-cell",children:[e.jsx("strong",{children:t.created_by_name||"—"}),e.jsx("small",{children:ge(t.created_at)})]}),e.jsx("td",{className:"col-actions",children:e.jsx(Ge,{items:[{id:"view",label:"View",icon:e.jsx(Qe,{size:14}),onClick:()=>$e(t)},i?{id:"edit",label:"Edit",icon:e.jsx(be,{size:14}),onClick:()=>oe(t)}:null,{id:"print",label:"Print",icon:e.jsx(he,{size:14}),onClick:()=>A(t)},{id:"pdf",label:"Save PDF",icon:e.jsx(pe,{size:14}),onClick:()=>A(t,{savePdf:!0})},c?{id:"delete",label:"Delete",icon:e.jsx(Xe,{size:14}),danger:!0,onClick:()=>Pe(t)}:null].filter(Boolean)})})]},t.slip_code||t.id||r))]})]})})]})}),o?e.jsx(xe,{title:"Delivery Note",className:"inventory-delivery-view-dialog",onClose:()=>P(null),footer:e.jsxs(e.Fragment,{children:[i?e.jsxs("button",{type:"button",className:"muted",onClick:()=>oe(o),children:[e.jsx(be,{size:15})," Edit"]}):null,e.jsxs("button",{type:"button",className:"muted",onClick:()=>A(o),children:[e.jsx(he,{size:15})," Print"]}),e.jsxs("button",{type:"button",className:"muted",onClick:()=>A(o,{savePdf:!0}),children:[e.jsx(pe,{size:15})," Save PDF"]}),e.jsx("button",{type:"button",className:"muted",onClick:()=>P(null),children:"Close"})]}),children:e.jsxs("div",{className:"inventory-delivery-view",children:[e.jsxs("div",{className:"inventory-delivery-view-hero",children:[e.jsxs("div",{children:[e.jsx("span",{children:"Slip Code"}),e.jsx("strong",{className:"inventory-delivery-slip-code",children:o.slip_code||"—"})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Items"}),e.jsx("strong",{children:b.format(o.item_count||(o.items||[]).length||0)})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Total Qty"}),e.jsx("strong",{className:"tone-blue",children:b.format(o.total_qty||0)})]})]}),e.jsxs("div",{className:"inventory-delivery-view-meta",children:[e.jsxs("div",{children:[e.jsx("span",{children:"Receiver"}),e.jsx("strong",{children:o.receiver_name||"—"})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Phone"}),e.jsx("strong",{children:o.receiver_phone||"—"})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Delivery Date"}),e.jsx("strong",{children:J(o.delivery_date||o.created_at)})]}),e.jsxs("div",{className:"wide",children:[e.jsx("span",{children:"Address"}),e.jsx("strong",{children:o.transfer_to||"—"})]}),e.jsxs("div",{className:"wide",children:[e.jsx("span",{children:"Note"}),e.jsx("strong",{children:k(o)||"—"})]}),e.jsxs("div",{className:"wide inventory-delivery-view-created",children:[e.jsx("span",{children:"Created By"}),e.jsx("strong",{children:o.created_by_name||"—"}),e.jsx("small",{children:ge(o.created_at)})]})]}),e.jsxs("section",{className:"inventory-delivery-view-products",children:[e.jsxs("header",{children:[e.jsx("strong",{children:"Products"}),e.jsxs("small",{children:[b.format((o.items||[]).length)," line(s)"]})]}),e.jsx("div",{className:"table-wrap",children:e.jsxs("table",{className:"inventory-delivery-view-table",children:[e.jsx("thead",{children:e.jsxs("tr",{children:[e.jsx("th",{className:"col-no",children:"No"}),e.jsx("th",{className:"col-product",children:"Product"}),e.jsx("th",{className:"col-qty",children:"Qty"})]})}),e.jsxs("tbody",{children:[(o.items||[]).length?null:e.jsx("tr",{children:e.jsx("td",{colSpan:3,className:"inventory-delivery-view-empty",children:"No item detail saved."})}),(o.items||[]).map((t,r)=>e.jsxs("tr",{children:[e.jsx("td",{className:"col-no",children:r+1}),e.jsxs("td",{className:"col-product",children:[e.jsx("strong",{children:t.product||"—"}),t.sku?e.jsx("small",{className:"inventory-delivery-item-sku",children:t.sku}):null]}),e.jsx("td",{className:"col-qty",children:b.format(t.qty||0)})]},`${t.movement_id||t.product||"item"}-${r}`))]})]})})]})]})}):null,v?e.jsx(xe,{title:`Edit Delivery Note · ${v.slip_code||""}`,className:"inventory-delivery-note-dialog",onClose:()=>!f&&$(null),footer:e.jsxs(e.Fragment,{children:[e.jsx("button",{type:"button",className:"muted",onClick:()=>$(null),disabled:f,children:"Cancel"}),e.jsxs("button",{type:"button",className:"primary",onClick:qe,disabled:f,children:[e.jsx(Ze,{size:15})," ",f?"Saving...":"Save"]})]}),children:e.jsxs("div",{className:"inventory-delivery-note-modal",children:[te?e.jsx("div",{className:"purchase-alert purchase-alert-error",role:"alert",children:te}):null,e.jsxs("div",{className:"inventory-delivery-note-summary",children:[e.jsxs("div",{children:[e.jsx("span",{children:"Slip Code"}),e.jsx("strong",{children:v.slip_code||"—"})]}),e.jsxs("div",{children:[e.jsx("span",{children:"Total Qty"}),e.jsx("strong",{className:"tone-blue",children:b.format(v.total_qty||0)})]})]}),e.jsxs("div",{className:"inventory-delivery-note-fields",children:[e.jsx(E,{label:"Receiver Name *",children:e.jsx("input",{type:"text",value:u.receiver_name,onChange:t=>q("receiver_name",t.target.value),placeholder:"Who receives the goods",disabled:f})}),e.jsx(E,{label:"Phone Number *",children:e.jsx("input",{type:"tel",value:u.phone_number,onChange:t=>q("phone_number",t.target.value),placeholder:"Contact number",disabled:f})}),e.jsx(E,{label:"Delivery Date *",children:e.jsx("input",{type:"date",value:u.delivery_date,onChange:t=>q("delivery_date",t.target.value),disabled:f})}),e.jsx(E,{label:"Address *",wide:!0,children:e.jsx("textarea",{rows:2,value:u.address,onChange:t=>q("address",t.target.value),placeholder:"Delivery address",disabled:f})}),e.jsx(E,{label:"Note",wide:!0,children:e.jsx("input",{type:"text",value:u.note,onChange:t=>q("note",t.target.value),placeholder:"Optional remark",disabled:f})})]})]})}):null]})}export{ft as default};
