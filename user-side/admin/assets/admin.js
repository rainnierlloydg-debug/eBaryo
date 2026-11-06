'use strict';
document.querySelectorAll('[data-dialog]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.dialog).showModal()));
document.querySelectorAll('dialog[data-auto-open]').forEach(dialog=>{dialog.showModal();dialog.addEventListener('cancel',event=>event.preventDefault());});
document.querySelectorAll('[data-reveal]').forEach(button=>button.addEventListener('click',()=>{const target=document.getElementById(button.dataset.reveal);target.hidden=false;target.querySelectorAll('input').forEach(input=>input.disabled=false);target.querySelector('input')?.focus();}));
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();const dialog=document.createElement('dialog');const title=document.createElement('h2');title.textContent=form.dataset.confirm;const row=document.createElement('div');row.className='button-row';const no=document.createElement('button');no.type='button';no.className='button secondary';no.textContent='No';const yes=document.createElement('button');yes.type='button';yes.className='button primary';yes.textContent='Yes';no.addEventListener('click',()=>{dialog.close();dialog.remove();});yes.addEventListener('click',()=>{yes.disabled=true;HTMLFormElement.prototype.submit.call(form);});row.append(no,yes);dialog.append(title,row);document.body.append(dialog);dialog.showModal();}));
document.querySelectorAll('form').forEach(form=>form.addEventListener('invalid',event=>{event.preventDefault();if(document.querySelector('dialog.validation-dialog'))return;const dialog=document.createElement('dialog');dialog.className='validation-dialog';const heading=document.createElement('h2');heading.textContent='Invalid';const detail=document.createElement('p');detail.textContent=event.target.validationMessage;const ok=document.createElement('button');ok.type='button';ok.className='button primary';ok.textContent='OK';ok.addEventListener('click',()=>{dialog.close();dialog.remove();event.target.focus();});dialog.append(heading,detail,ok);document.body.append(dialog);dialog.showModal();},true));
const recipientType=document.getElementById('recipient-type');
if(recipientType){const update=()=>{document.getElementById('resident-picker').hidden=recipientType.value==='All';};recipientType.addEventListener('change',update);update();}
document.getElementById('recipient-search')?.addEventListener('input',event=>{const term=event.target.value.toLocaleLowerCase();document.querySelectorAll('.recipient-choice').forEach(label=>label.hidden=!label.textContent.toLocaleLowerCase().includes(term));});
const editor=document.getElementById('document-editor');
if(editor){
 let range=null;
 document.addEventListener('selectionchange',()=>{const selection=window.getSelection();if(selection.rangeCount&&editor.contains(selection.anchorNode))range=selection.getRangeAt(0).cloneRange();});
 const format=(command,value=null)=>{editor.focus();if(range){const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);}document.execCommand(command,false,value);};
 document.querySelectorAll('[data-command]').forEach(button=>button.addEventListener('mousedown',event=>event.preventDefault()));
 document.querySelectorAll('[data-command]').forEach(button=>button.addEventListener('click',()=>format(button.dataset.command)));
 document.getElementById('block-style').addEventListener('change',event=>format('formatBlock',event.target.value));
 editor.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));});
 editor.closest('form').addEventListener('submit',()=>{document.getElementById('document-content').value=editor.innerHTML;});
}
document.getElementById('print-document')?.addEventListener('click',()=>window.print());
