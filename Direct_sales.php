 ]);
 const percentHeaders=new Set(['% Share','% Share of LS Balance']);

 function sheetFromTable(table){
   const matrix=[];
   const trs=[...table.querySelectorAll('tr')];
   trs.forEach(tr=>{
     const row=[...tr.querySelectorAll('th,td')].map(cell=>cell.textContent.trim());
     matrix.push(row);
   });
   if(!matrix.length)return XLSX.utils.aoa_to_sheet([]);

   const headers=matrix[0];
   const numericIndexes=new Set();
   const percentIndexes=new Set();
   headers.forEach((h,i)=>{
     if(numericHeaders.has(h))numericIndexes.add(i);
     if(percentHeaders.has(h))percentIndexes.add(i);
   });

   const aoa=matrix.map((row,ri)=>row.map((v,ci)=>{
     if(ri===0 || !numericIndexes.has(ci)) return v;
     if(v==='' || v==='-' || v===null) return null;
     const cleaned=String(v).replace(/,/g,'').replace(/%/g,'').trim();
     const n=Number(cleaned);
     if(!Number.isFinite(n)) return v;
     return percentIndexes.has(ci) ? n/100 : n;
   }));

   const ws=XLSX.utils.aoa_to_sheet(aoa);
   const range=XLSX.utils.decode_range(ws['!ref']||'A1');
   for(let c=range.s.c;c<=range.e.c;c++){
     const header=headers[c]||'';
     if(!numericHeaders.has(header))continue;
     for(let r=1;r<=range.e.r;r++){
       const addr=XLSX.utils.encode_cell({r,c});
       const cell=ws[addr];
       if(!cell || cell.v===null || typeof cell.v!=='number')continue;
       cell.t='n';
       if(percentHeaders.has(header)) cell.z='0.00%';
       else if(header==='Exchange Rate') cell.z='#,##0.00####';
       else if(header.includes('Price') || header.includes('Value')) cell.z='#,##0.00####';
       else cell.z='#,##0.###';
     }
   }
   return ws;
 }

 if($('display').value==='summary'){
   const opts={coffee:['Coffee Type',$('coffeeSummary').querySelector('table')],region:['Region',$('regionSummary').querySelector('table')],supplier:['Supplier by Coffee',$('supplierCoffeeSummary').querySelector('table')],buyer:['Buyer by Coffee',$('buyerCoffeeSummary').querySelector('table')],
     supplier_region:['Supplier by Region',$('supplierRegionSummary').querySelector('table')],
     buyer_region:['Buyer by Region',$('buyerRegionSummary').querySelector('table')]};
   const [name,table]=opts[$('summaryType').value]; if(table)XLSX.utils.book_append_sheet(wb,sheetFromTable(table),name);
 }else{
   const table=$('table');
   if(table)XLSX.utils.book_append_sheet(wb,sheetFromTable(table),'All Sales');
 }
 XLSX.writeFile(wb,exportFileName('xlsx'),{cellStyles:true});
}
async function exportPdf(){
 if(!window.jspdf||typeof html2canvas==='undefined'){alert('PDF export library is not available.');return}
 $('exportMenu').classList.remove('show');
 const source=currentExportNode();
 const clone=source.cloneNode(true);
 clone.style.display='block';clone.style.position='fixed';clone.style.left='-100000px';clone.style.top='0';
 clone.style.width=$('display').value==='summary'?'1100px':'1800px';clone.style.height='auto';clone.style.maxHeight='none';clone.style.overflow='visible';
 clone.querySelectorAll('*').forEach(el=>{el.style.maxHeight='none';el.style.height='auto';el.style.overflow='visible'});
 document.body.appendChild(clone);
 try{
   const canvas=await html2canvas(clone,{scale:1.5,backgroundColor:'#ffffff',useCORS:true});
   const {jsPDF}=window.jspdf;
   const landscape=canvas.width>canvas.height;
   const pdf=new jsPDF({orientation:landscape?'landscape':'portrait',unit:'mm',format:'a4'});
   const pw=pdf.internal.pageSize.getWidth(),ph=pdf.internal.pageSize.getHeight(),margin=7;
   const iw=pw-margin*2,ih=canvas.height*iw/canvas.width;
   const pageH=ph-margin*2;
   if(ih<=pageH){
     pdf.addImage(canvas.toDataURL('image/jpeg',.94),'JPEG',margin,margin,iw,ih);
   }else{
     const pxPage=Math.floor(canvas.width*pageH/iw);
     let y=0,page=0;
     while(y<canvas.height){
       const h=Math.min(pxPage,canvas.height-y),slice=document.createElement('canvas');
       slice.width=canvas.width;slice.height=h;
       slice.getContext('2d').drawImage(canvas,0,y,canvas.width,h,0,0,canvas.width,h);
       if(page++)pdf.addPage(undefined,landscape?'landscape':'portrait');
       pdf.addImage(slice.toDataURL('image/jpeg',.94),'JPEG',margin,margin,iw,h*iw/canvas.width);
       y+=h;
     }
   }
   pdf.save(exportFileName('pdf'));
 }finally{clone.remove()}
}
function exportCurrent(type){
 $('exportMenu').classList.remove('show');
 if(type==='excel')exportExcel();
 else if(type==='word')exportWord();
 else exportPdf();
}

(async()=>{await loadFilters();await refreshCurrent()})();
</script></body></html>