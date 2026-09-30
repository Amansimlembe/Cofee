   if(expanded)others.forEach((p,i)=>h+=row(p,11+i,'tc-detail-row'));
  }
  if(q&&!filtered.length)h+=`<tr><td colspan="${4+dims.length*2}" style="text-align:center">No matching ${esc(cfg.partyLabel.toLowerCase())} found.</td></tr>`;
  h+='</tbody><tfoot>'+aggRow(q?`Filtered Total (${filtered.length})`:'Overall Total',aggregate(q?filtered:allParties),'tc-overall-row')+'</tfoot></table>';
  box.innerHTML=h;
  status.textContent=q?`${filtered.length} matching ${cfg.partyLabel.toLowerCase()} record(s) · export will use the filtered result.`:`Showing Top ${Math.min(10,allParties.length)} of ${allParties.length} ${cfg.partyLabel.toLowerCase()} records · ${others.length} other${others.length===1?'':'s'}.`;
  document.getElementById('tcOtherToggle')?.addEventListener('click',()=>{expanded=!expanded;draw()});
 }
 sel.addEventListener('change',()=>{expanded=false;if(search)search.value='';draw()});
 search?.addEventListener('input',()=>{expanded=false;draw()});
 draw();

 if(exportBtn&&exportMenu){
  exportBtn.addEventListener('click',e=>{e.stopPropagation();exportMenu.classList.toggle('show')});
  document.addEventListener('click',()=>exportMenu.classList.remove('show'));
  const exportTable=()=>document.getElementById('tcPivotExportTable');
  const fname=ext=>`Clean_Coffee_${sel.value}_${search?.value?'Filtered':'Top10'}_<?=preg_replace('/[^0-9A-Za-z_-]/','_', $season)?>.${ext}`;
  function xlsx(){
   const table=exportTable();if(!table||!window.XLSX)return;
   const wb=XLSX.utils.table_to_book(table,{sheet:'Analysis',raw:true});
   const ws=wb.Sheets.Analysis,range=XLSX.utils.decode_range(ws['!ref']);
   for(let r=2;r<=range.e.r;r++)for(let c=2;c<=range.e.c;c++){const a=XLSX.utils.encode_cell({r,c}),cell=ws[a];if(!cell)continue;const n=Number(String(cell.v).replace(/,/g,''));if(Number.isFinite(n)){cell.v=n;cell.t='n';cell.z='#,##0.00####';}}
   XLSX.writeFile(wb,fname('xlsx'));
  }
  function pdf(){
   const table=exportTable();if(!table||!window.jspdf)return;const {jsPDF}=window.jspdf,doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
   doc.setFontSize(11);doc.text('Clean Coffee Buyer / Supplier-Seller Analysis',10,10);
   doc.setFontSize(7);doc.text(`Sale Season <?=htmlspecialchars($season)?> · ${sel.options[sel.selectedIndex].text}${search?.value?' · Filter: '+search.value:''}`,10,15);
   doc.autoTable({html:table,startY:19,theme:'grid',styles:{fontSize:4.8,cellPadding:.8},headStyles:{fontStyle:'bold'},footStyles:{fontStyle:'bold'}});
   doc.save(fname('pdf'));
  }
  function word(){
   const table=exportTable();if(!table)return;const clone=table.cloneNode(true);clone.querySelectorAll('th,td').forEach(c=>c.style.cssText='border:1px solid #555;padding:3px;font-family:Arial;font-size:8pt');clone.style.cssText='border-collapse:collapse;width:100%';
   const html=`<html><head><meta charset="utf-8"></head><body><h3>Clean Coffee Buyer / Supplier-Seller Analysis</h3><p>Sale Season <?=htmlspecialchars($season)?> · ${esc(sel.options[sel.selectedIndex].text)}${search?.value?' · Filter: '+esc(search.value):''}</p>${clone.outerHTML}</body></html>`;
   const b=new Blob(['\ufeff',html],{type:'application/msword'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=fname('doc');a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500);
  }
  exportMenu.addEventListener('click',e=>{const f=e.target.dataset.format;if(!f)return;e.stopPropagation();exportMenu.classList.remove('show');f==='xlsx'?xlsx():f==='pdf'?pdf():word()});
 }
})();
</script>

<script>
(function(){
 const btn=document.getElementById('tcExportBtn'),menu=document.getElementById('tcExportMenu'),table=document.getElementById('totalCleanSalesTable');
 if(!btn||!menu||!table)return;
 const season=<?=json_encode($season)?>;
 const safe=s=>String(s).replace(/[^A-Za-z0-9_-]+/g,'_');
 const filename=ext=>`Coffee_Sales_Summary_${safe(season)}.${ext}`;
 btn.addEventListener('click',e=>{e.stopPropagation();menu.classList.toggle('show')});
 document.addEventListener('click',()=>menu.classList.remove('show'));

 function matrix(){
   return [...table.rows].map(r=>[...r.cells].map(c=>c.innerText.trim()));
 }
 function numeric(v,percent=false){
   if(v===''||v==='-')return null;
   const n=Number(String(v).replace(/,/g,'').replace(/%/g,'').trim());
   return Number.isFinite(n)?(percent?n/100:n):v;
 }
 function exportExcel(){
   if(!window.XLSX){alert('Excel export library is not available.');return}
   const aoa=matrix();
   const ws=XLSX.utils.aoa_to_sheet(aoa);
   const range=XLSX.utils.decode_range(ws['!ref']);
   // Rows 3 onward contain data. First column is text; all remaining populated cells are numeric.
   for(let r=2;r<=range.e.r;r++){
     for(let c=1;c<=range.e.c;c++){
       const a=XLSX.utils.encode_cell({r,c}),cell=ws[a];
       if(!cell||cell.v==='')continue;
       const isPct=(c===range.e.c)||(aoa[r]&&aoa[r][0]==='Channel Share'&&(c===1||c===4||c===7||c===10));
       const n=numeric(cell.v,isPct);
       if(typeof n==='number'){cell.v=n;cell.t='n';cell.z=isPct?'0.00%':'#,##0.00####';}
     }
   }
   ws['!cols']=[{wch:18},...Array(15).fill({wch:12})];
   const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Coffee Sales Summary');
   XLSX.writeFile(wb,filename('xlsx'),{cellStyles:true});
 }
 function exportPDF(){
   if(!window.jspdf||!window.jspdf.jsPDF){alert('PDF export library is not available.');return}
   const {jsPDF}=window.jspdf,doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
   doc.setFont('helvetica','bold');doc.setFontSize(13);doc.text('Coffee Sales Summary',14,13);
   doc.setFont('helvetica','normal');doc.setFontSize(8);doc.text(`Clean coffee · Sale Season ${season}`,14,18);
   doc.autoTable({html:'#totalCleanSalesTable',startY:22,theme:'grid',
     styles:{fontSize:5.7,cellPadding:1.2,textColor:[40,31,27],lineColor:[91,64,51],lineWidth:.15,fillColor:false},
     headStyles:{fontStyle:'bold',textColor:[58,39,30],fillColor:[245,240,236],lineWidth:.25},
     footStyles:{fontStyle:'bold',textColor:[58,39,30],fillColor:[250,247,245],lineWidth:.25},
     columnStyles:{0:{cellWidth:24,halign:'left'}},
     didParseCell:d=>{if(d.column.index>0)d.cell.styles.halign='right';}
   });
   doc.save(filename('pdf'));
 }
 function exportWord(){
   const cloned=table.cloneNode(true);
   cloned.querySelectorAll('th,td').forEach(c=>c.setAttribute('style','border:1px solid #5b4033;padding:4px;font-family:Arial;font-size:9pt;'));
   cloned.setAttribute('style','border-collapse:collapse;width:100%;');
   const html=`<!doctype html><html><head><meta charset="utf-8"><title>Coffee Sales Summary</title></head><body><h2 style="font-family:Arial;color:#4b2e20;margin-bottom:3px">Coffee Sales Summary</h2><div style="font-family:Arial;font-size:9pt;margin-bottom:10px">Clean coffee · Sale Season ${season}</div>${cloned.outerHTML}</body></html>`;
   const blob=new Blob(['\ufeff',html],{type:'application/msword'});
   const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=filename('doc');document.body.appendChild(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},500);
 }
 menu.addEventListener('click',e=>{const f=e.target.dataset.format;if(!f)return;e.stopPropagation();menu.classList.remove('show');if(f==='pdf')exportPDF();else if(f==='xlsx')exportExcel();else exportWord();});
})();
</script>

<script>
const auctionTrend=<?=json_encode(in_array($display,['sales','preauction','direct','totalclean'],true)?[]:$auctionTrend,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,cleanMode=<?=json_encode($display==='clean')?>,ctx=document.getElementById('auctionTrendChart');
if(ctx&&window.Chart){const datasets=cleanMode?[
{type:'bar',label:'Quantity Sold (kg)',data:auctionTrend.map(r=>Number(r.qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:24},
{type:'line',label:'Weighted Avg Price (USD/50kg)',data:auctionTrend.map(r=>r.avg_price===null?null:Number(r.avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25}
]:[
{type:'bar',label:'Dry Cherry Qty (kg)',data:auctionTrend.map(r=>Number(r.dry_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
{type:'bar',label:'Clean Coffee Qty (kg)',data:auctionTrend.map(r=>Number(r.clean_qty)||0),yAxisID:'yQty',borderWidth:0,maxBarThickness:18},
{type:'line',label:'Dry Cherry Avg Price',data:auctionTrend.map(r=>r.dry_avg_price===null?null:Number(r.dry_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25},
{type:'line',label:'Clean Coffee Avg Price',data:auctionTrend.map(r=>r.clean_avg_price===null?null:Number(r.clean_avg_price)),yAxisID:'yPrice',borderWidth:2,pointRadius:2,tension:.25}];
new Chart(ctx,{data:{labels:auctionTrend.map(r=>'A'+r.auction_no),datasets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',labels:{boxWidth:9,boxHeight:9,font:{size:8},padding:8}}},scales:{x:{grid:{display:false},ticks:{font:{size:8},autoSkip:false},title:{display:true,text:'Auction No.',font:{size:8}}},yQty:{position:'left',beginAtZero:true,title:{display:true,text:'Quantity sold (kg)',font:{size:8}},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}},yPrice:{position:'right',title:{display:true,text:cleanMode?'Avg. price (USD/50kg)':'Avg. price (TZS/kg)',font:{size:8}},grid:{drawOnChartArea:false},ticks:{font:{size:8},callback:v=>Number(v).toLocaleString()}}}}});}
</script>
</body>
</html>
