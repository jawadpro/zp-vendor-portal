/* global ZPVP_CONTACT_SEARCH */
(() => {
 'use strict';
 const box = document.querySelector('.zpvp-contact-picker');
 if (!box || !window.ZPVP_CONTACT_SEARCH) return;
 const input = box.querySelector('#zpvp-add-contact');
 const email = box.querySelector('#zpvp-contact-email');
 const list = box.querySelector('#zpvp-contact-results');
 const status = box.querySelector('.zpvp-contact-status');
 const clear = box.querySelector('.zpvp-contact-clear');
 const save = box.querySelector('.zpvp-contact-save');
 let timer, controller, version = 0, rows = [], active = -1;
 const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
 function cancel() { clearTimeout(timer); controller?.abort(); version++; box.classList.remove('is-searching'); }
 function choose(index) {
  const vendor = rows[index]; if (!vendor) return;
  cancel(); email.value = vendor.email; input.value = vendor.name + ' · ' + vendor.email;
  box.classList.add('has-selection'); clear.hidden = false; save.disabled = false; close();
  status.textContent = 'Selected ' + vendor.name + '. Click Save contact to add them.';
 }
 function highlight(index) {
  active = index;
  Array.from(list.children).forEach((item, i) => { item.setAttribute('aria-selected', String(i === active)); });
  if (active >= 0) { input.setAttribute('aria-activedescendant', list.children[active].id); list.children[active].scrollIntoView({block:'nearest'}); }
 }
 async function search(term, requestVersion) {
  controller = new AbortController(); box.classList.add('is-searching'); status.textContent = 'Searching vendors…';
  try {
   const url = new URL(ZPVP_CONTACT_SEARCH.url);
   url.search = new URLSearchParams({action:'zpvp_find_vendor', nonce:ZPVP_CONTACT_SEARCH.nonce, term, company:box.dataset.company}).toString();
   const response = await fetch(url, {credentials:'same-origin', signal:controller.signal});
   const result = await response.json();
   if (requestVersion !== version) return;
   if (!response.ok || !result.success || !Array.isArray(result.data)) throw new Error('Search unavailable');
   rows = result.data; list.replaceChildren(); active = -1;
   rows.forEach((vendor, i) => {
    const option = document.createElement('li'); option.id = 'zpvp-vendor-option-' + i; option.setAttribute('role','option'); option.setAttribute('aria-selected','false');
    const avatar = document.createElement('span'); avatar.className = 'zpvp-vendor-avatar'; avatar.setAttribute('aria-hidden','true'); avatar.textContent = vendor.name.replace(/\[TEST\]\s*/g,'').trim().slice(0,1).toUpperCase();
    const details = document.createElement('span'); details.className = 'zpvp-vendor-details';
    const name = document.createElement('strong'); name.textContent = vendor.name;
    const address = document.createElement('span'); address.textContent = vendor.email;
    const company = document.createElement('small'); company.textContent = vendor.company || 'No company assigned';
    details.append(name,address,company); option.append(avatar,details);
    option.addEventListener('mousedown',e=>e.preventDefault()); option.addEventListener('click',()=>choose(i)); list.append(option);
   });
   list.hidden = !rows.length; input.setAttribute('aria-expanded',String(rows.length > 0));
   status.textContent = rows.length ? rows.length + ' vendor' + (rows.length === 1 ? '' : 's') + ' found. Select a contact.' : 'No matching vendors. Try another name or email.';
  } catch(error) { if (requestVersion === version && error.name !== 'AbortError') { close(); status.textContent = 'Could not search vendors. Please try again.'; } }
  finally { if (requestVersion === version) box.classList.remove('is-searching'); }
 }
 input.addEventListener('input',()=>{
  cancel(); save.disabled=true; email.value=''; rows=[]; close(); box.classList.remove('has-selection'); clear.hidden=!input.value;
  const term=input.value.trim();
  if(term.length<2){status.textContent='Type at least 2 characters to search.';return;}
  status.textContent='Searching vendors…'; const current=version; timer=setTimeout(()=>search(term,current),300);
 });
 input.addEventListener('keydown',e=>{
  if(e.key==='Escape'){cancel();close();return;}
  if(!list.hidden && rows.length && ['ArrowDown','ArrowUp','Enter'].includes(e.key)){
   e.preventDefault(); if(e.key==='Enter'){if(active>=0)choose(active);}else highlight((active+(e.key==='ArrowDown'?1:rows.length-1)+rows.length)%rows.length);
  } else if(e.key==='Enter' && !email.value && input.value.trim()) {e.preventDefault();status.textContent='Select a vendor from the search results first.';}
 });
 input.addEventListener('blur',()=>{close();});
 clear.addEventListener('click',()=>{cancel();save.disabled=true;input.value='';email.value='';rows=[];close();box.classList.remove('has-selection');clear.hidden=true;status.textContent='Type at least 2 characters to search.';input.focus();});
 save.addEventListener('click',async()=>{
  if(!email.value || save.disabled) return;
  cancel(); close(); save.disabled=true; input.disabled=true; clear.disabled=true; save.textContent='Saving contact…'; status.textContent='Saving contact…'; box.setAttribute('aria-busy','true');
  try {
   const response=await fetch(ZPVP_CONTACT_SEARCH.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'zpvp_save_contact',nonce:ZPVP_CONTACT_SEARCH.saveNonce,company:box.dataset.company,email:email.value})});
   const result=await response.json();
   if(!response.ok || !result.success) throw new Error(result.data?.message || 'Could not save contact. Please try again.');
   const parsed=new DOMParser().parseFromString(result.data.contacts,'text/html');
   const table=parsed.querySelector('table'); const current=document.querySelector('#zpvp-contacts table');
   if(table && current) current.replaceWith(table);
   const registrations=document.querySelector('#zpvp-company-regs .inside'); if(registrations) registrations.innerHTML=result.data.registrations;
   input.value='';email.value='';rows=[];box.classList.remove('has-selection');clear.hidden=true;status.textContent=result.data.message;box.classList.add('is-saved');
  } catch(error){status.textContent=error.message;save.disabled=!email.value;}
  finally {input.disabled=false;clear.disabled=false;save.textContent='Save contact';box.setAttribute('aria-busy','false');}
 });
 box.closest('form')?.addEventListener('submit',e=>{if(input.value.trim()){e.preventDefault();status.textContent=email.value?'Click Save contact to add this vendor first.':'Select a vendor from the search results, or clear the search before saving.';input.focus();}});
})();
