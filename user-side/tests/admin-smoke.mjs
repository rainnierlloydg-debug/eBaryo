import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import net from 'node:net';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const database=`barangay_user_smoke_${randomBytes(6).toString('hex')}`;
const php=process.env.PHP_BINARY||'php';
function db(mode,admin=true){const r=spawnSync(php,[admin?'tests/admin-db.php':'tests/db-tool.php',mode,database],{cwd:root,encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
let checks=0;function check(value,message){assert.ok(value,message);checks++;}
const listener=net.createServer();await new Promise(r=>listener.listen(0,'127.0.0.1',r));const port=listener.address().port;await new Promise(r=>listener.close(r));
const origin=`http://127.0.0.1:${port}`;let server;let logs='';
class Client{
 cookie='';
 async get(url='index.php'){return this.request(url);}
 async request(url,options={}){const r=await fetch(origin+'/'+url,{...options,redirect:'manual',headers:{Cookie:this.cookie,...options.headers}});for(const c of r.headers.getSetCookie())this.cookie=c.split(';')[0];return {status:r.status,location:r.headers.get('location'),body:await r.text()};}
 async post(action,values={},page='admin/index.php',endpoint='admin/action.php',files={}){const html=await this.get(page);const token=/name="csrf" value="([^"]+)"/.exec(html.body)?.[1];assert.ok(token,'CSRF exists on '+page);const form=new FormData();form.set('action',action);form.set('csrf',token);for(const [k,v]of Object.entries(values)){if(Array.isArray(v))for(const item of v)form.append(k,item);else form.set(k,v);}for(const [key,file]of Object.entries(files))form.set(key,new Blob([file.data],{type:file.type}),file.name);return this.request(endpoint,{method:'POST',body:form});}
 async follow(r){assert.equal(r.status,303,r.body);return this.get(r.location.startsWith('admin/')?r.location:r.location.startsWith('../')?r.location.slice(3):'admin/'+r.location);}
 async login(email){return this.post('login',{email,password:'TestPass123!'},'index.php?page=login','action.php');}
}
const state=()=>JSON.parse(db('inspect'));
try{
 db('setup',false);db('setup');
 server=spawn(php,['-S',`127.0.0.1:${port}`,'router.php'],{cwd:root,env:{...process.env,BARANGAY_DB_NAME:database},windowsHide:true});server.stderr.on('data',c=>logs+=c);
 for(let i=0;i<60;i++){try{await fetch(origin);break;}catch{await new Promise(r=>setTimeout(r,100));}}
 const anon=new Client(),admin=new Client(),resident=new Client();
 for(const page of ['dashboard','requests','residents','documents','notifications','reports','settings','editor'])check((await anon.get('admin/index.php?page='+page)).location==='../index.php?page=login','Anonymous denied '+page);
 await resident.login('juan@example.test');check((await resident.get('admin/index.php')).location==='../index.php?page=login','Resident denied admin');
 check((await admin.login('admin@example.test')).location==='admin/index.php','Shared login routes admin');
 check((await admin.request('admin/action.php',{method:'POST',body:new URLSearchParams({action:'delete',id:'1'})})).status===419,'CSRF required');
 check((await admin.get('admin/action.php')).status===405,'Mutation endpoint POST only');
 for(const page of ['dashboard','requests','residents','documents','notifications','reports','settings'])check((await admin.get('admin/index.php?page='+page)).status===200,'Page renders '+page);
 check((await admin.get('admin/index.php?page=requests&search=REQ-000001')).body.includes('Juan Cruz'),'Track by request ID');
 check((await admin.get('admin/index.php?page=requests&search=absent')).body.includes('No record found'),'Missing request');
 check((await admin.get('admin/index.php?page=requests&search=')).body.includes('<h2>Invalid</h2>'),'Empty request search');
 check((await admin.get('admin/index.php?page=requests&status=Submitted')).body.includes('REQ-000001'),'Legacy Pending matches Submitted');
 let page=await admin.follow(await admin.post('review',{id:'1',status:'Rejected',reason:'Missing residency proof'}));
 check(page.body.includes('Message Updated')&&state().document_requests[0].status==='Rejected','Review persists');
 check(state().notifications[0].message.includes('Missing residency proof'),'Review reason delivered');
 const update={id:'1',status:'Approved',payment_status:'Paid',payment_amount:'50.00',pickup_date:'2026-11-01T10:30',processing_date:'2026-10-25T09:00',processing:'Processing',pickup_option:'Pick up',delivery_option:'Not needed'};
 await admin.post('update_request',update);check(state().document_requests[0].status==='Processing'&&state().document_requests[0].payment_status==='Paid','Status and payment saved');
 const before=state().notifications.length;await admin.post('update_request',{...update,payment_amount:'-1'});check(state().notifications.length===before,'Invalid update rolls back');
 const editor=await admin.get('admin/index.php?page=editor&id=1');const version=/name="version" value="([^"]+)"/.exec(editor.body)[1];
 await admin.post('save_document_content',{id:'1',version,mode:'draft',content:'<h1 onclick="evil()">Certificate</h1><script>alert(1)</script><p>Juan Cruz</p>'});
 check(state().document_requests[0].document_content==='<h1>Certificate</h1><p>Juan Cruz</p>','Rich document sanitized and saved');
 page=await admin.follow(await admin.post('save_document_content',{id:'1',version,mode:'draft',content:'Stale update'}));check(page.body.includes('Another update was saved'),'Concurrent draft rejected');
 check((await admin.get('admin/index.php?page=residents&search=Maria')).body.includes('maria@example.test'),'Resident search');
 await admin.post('registration',{id:'3',status:'Approved'});check(state().users[2].status==='Verified','Approve uses resident-compatible status');
 await admin.post('registration',{id:'4',status:'Rejected'});check(state().users[3].status==='Rejected','Reject registration');
 const doc={name:'Test Document',description:'Test description',purpose:'Test purpose',requirements:'Valid ID',fee:'12.50',processing_time:'2 days'};
 await admin.post('save_document',doc);let s=state();const docId=s.documents.at(-1).id;check(s.documents.at(-1).purpose==='Test purpose','Add document');
 await admin.post('save_document',{...doc,id:String(docId),fee:'20.00'});check(state().documents.at(-1).fee==='20.00','Edit document');
 await admin.post('deactivate',{id:String(docId)});check(state().documents.at(-1).is_active===0,'Deactivate document');
 check(!(await resident.get('index.php?page=request')).body.includes('Test Document'),'Inactive hidden from resident');
 await admin.post('delete',{id:'1'});s=state();check(s.document_requests[0].document_id===null&&s.document_requests[0].document_name==='Barangay Clearance','Delete preserves request snapshots');
 await admin.post('send_notification',{title:'Office hours',message:'Open tomorrow',recipient_type:'Specific',priority_id:'2','recipients[]':['2','3']});
 s=state();check(s.announcements.length===1&&s.notifications.filter(n=>n.announcement_id===1).length===2,'Specific announcement recipients');
 check(s.email_outbox.filter(n=>n.subject==='Office hours').length===1,'Email preference respected');
 page=await admin.follow(await admin.post('send_notification',{title:'Bad',message:'Message',recipient_type:'Custom',priority_id:'1','recipients[]':['1']}));check(page.body.includes('Invalid')&&state().announcements.length===1,'Admin excluded from resident picker');
 await admin.post('send_notification',{title:'Attachment',message:'Please read',recipient_type:'Custom',priority_id:'1','recipients[]':['2']},'admin/index.php?page=notifications','admin/action.php',{attachment:{data:'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF',type:'application/pdf',name:'notice.pdf'}});
 check(state().announcements.length===2,'Attachment saved');
 check((await resident.get('attachment.php?announcement=2')).body.startsWith('%PDF'),'Recipient can download attachment');
 const other=new Client();await other.login('maria@example.test');check((await other.get('attachment.php?announcement=2')).status===404,'Attachment denied to nonrecipient');
 check((await admin.get('uploads/announcements/anything.pdf')).status===404,'Direct uploads blocked');
 await admin.post('read_report',{id:'1'});check(state().admin_request_reads.length===1,'Single report read');
 await admin.post('read_reports',{'ids[]':['1','2']});check(state().admin_request_reads.length===2,'All displayed reports read');
 page=await admin.get('admin/index.php?page=reports&open=1');check(page.body.includes('report-read')&&page.body.includes('Certificate</h1>'),'Report greys and displays saved content');
 await admin.post('save_preferences',{email_updates:'0',in_app_notifications:'0'});check(state().user_preferences[0].email_updates===0,'Admin preferences save');
 page=await admin.follow(await admin.post('change_password',{current_password:'Wrong',new_password:'NewPass123!',confirm_password:'NewPass123!'}));check(page.body.includes('Invalid'),'Current password checked');
 await admin.post('change_password',{current_password:'TestPass123!',new_password:'NewPass123!',confirm_password:'NewPass123!'});check(state().admin_audit_logs.some(l=>l.action==='change_password'),'Password change audited');
 await admin.post('logout');check((await admin.get('admin/index.php')).location==='../index.php?page=login','Logout destroys admin access');
 check(!logs.includes('Fatal error')&&!logs.includes('Warning:'),'No PHP runtime warnings');
 console.log(`PASS: ${checks} admin HTTP/database checks.`);
}finally{
 if(server){server.kill();await new Promise(r=>{server.once('exit',r);setTimeout(r,1500);});}
 try{db('cleanup');db('cleanup',false);}catch(e){console.error(e.message);}
}
