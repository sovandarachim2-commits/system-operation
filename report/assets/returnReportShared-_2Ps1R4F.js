const g=new Intl.NumberFormat("en-US",{style:"currency",currency:"USD"}),u=new Intl.NumberFormat("en-US");function b(t=new Date){const r=t.getFullYear(),e=String(t.getMonth()+1).padStart(2,"0"),o=String(t.getDate()).padStart(2,"0");return`${r}-${e}-${o}`}function h(t=new Date){return`${t.getFullYear()}-${String(t.getMonth()+1).padStart(2,"0")}-01`}function f(t,r="en"){const e=String(t||"")==="scanner";return r==="km"?e?"ម៉ាស៊ីនស្កេន":"គ្រប់គ្រងកម្មង់":e?"Scanner":"Order Management"}function w(t,r,e,o){const i=(e[0]?.language||"en")==="km"?"បង្កើត":"Generated",l=`<!doctype html><html><head><meta charset="utf-8"><style>
    body{font-family:Arial,"Khmer OS Battambang","Noto Sans Khmer",sans-serif;color:#000;}
    .report-title{font-size:18px;font-weight:700;text-align:center;}
    .report-subtitle{font-size:12px;font-weight:700;text-align:center;color:#334155;}
    table{border-collapse:collapse;width:100%;margin-top:10px;}
    caption{font-weight:700;margin-bottom:8px;text-align:left;}
    th,td{mso-number-format:"\\@";vertical-align:top;white-space:normal;border:1px solid #000;padding:5px;}
    th{background:#f1f5f9;font-weight:700;text-align:center;}
    tfoot td{background:#f8fafc;font-weight:700;}
  </style></head><body><div class="report-title">${n(t)}</div><div class="report-subtitle">${n(r)}</div><div class="report-subtitle">${n(i)}: ${n(new Date().toLocaleString())}</div>${e.map(m).join("<br>")}</body></html>`,c=new Blob([l],{type:"application/vnd.ms-excel;charset=utf-8;"}),p=URL.createObjectURL(c),s=document.createElement("a");s.href=p,s.download=`${o}.xls`,document.body.appendChild(s),s.click(),s.remove(),URL.revokeObjectURL(p)}function $(t,r,e){document.getElementById("print-root")?.remove();const o=document.createElement("div");o.id="print-root";const a=e.length===1?e[0].title:"",l=(e[0]?.language||"en")==="km"?"បង្កើត":"Generated";o.innerHTML=`
    <div class="operation-stock-print return-report-print">
      <header class="operation-stock-print-header">
        <h1>${n(t)}${a?` <strong>${n(a)}</strong>`:""}</h1>
        <p class="operation-stock-print-generated">${n(r)}</p>
        <p class="operation-stock-print-generated">${n(l)}: ${n(new Date().toLocaleString())}</p>
      </header>
      ${e.map(m).join("")}
    </div>
  `,document.body.appendChild(o);const c=()=>{document.body.classList.remove("printing-panel","printing-return-report"),o.remove(),window.removeEventListener("afterprint",c),window.clearTimeout(p)},p=window.setTimeout(c,6e4);document.body.classList.add("printing-panel","printing-return-report"),window.addEventListener("afterprint",c),window.requestAnimationFrame(()=>{window.requestAnimationFrame(()=>{window.print()})})}function m(t){const r=t.columns.map(a=>`<th>${n(a.label)}</th>`).join(""),e=t.rows.length?t.rows.map((a,i)=>`<tr class="operation-item-row">${t.columns.map(l=>`<td>${d(l.value(a,i))}</td>`).join("")}</tr>`).join(""):`<tr><td colspan="${t.columns.length}">${t.language==="km"?"គ្មានទិន្នន័យ":"No data"}</td></tr>`,o=t.footerRows?.length?`<tfoot>${t.footerRows.map(a=>`<tr class="operation-subtotal-row">${a.map(i=>`<td>${d(i)}</td>`).join("")}</tr>`).join("")}</tfoot>`:"";return`<table border="1" cellspacing="0" cellpadding="5"><caption>${n(t.title)}</caption><thead><tr>${r}</tr></thead><tbody>${e}</tbody>${o}</table>`}function d(t){return n(t).replace(/\r?\n/g,"<br>")}function n(t){return String(t??"").replace(/[&<>"']/g,r=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"})[r])}export{g as a,w as e,b as i,h as m,u as n,$ as p,f as s};
