const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const test = require('node:test');
const root = path.resolve(__dirname, '../../../..');
const {JSDOM} = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const flush = () => new Promise(resolve => setImmediate(resolve));
const key = 'wayfindr.assist.rail.synthetic-user';
const state = {mode:'manual',state:'ready',generation:'2f07b4b0-6c86-49c9-aef7-b63498df8395',policy_revision:'policy',output:{text:'Approved private reply.'},can_use:true,can_prepare:true,retry_after:0};
const copy = {states:{ready:'Ready',stale:'Stale',unavailable:'Unavailable'},use:'Insert into reply',refresh:'Refresh',blocked:'Edits preserved',used:'Inserted'};
const attr = value => JSON.stringify(value).replaceAll('&','&amp;').replaceAll('"','&quot;');
function fixture(t, options={}) {
  const dom = new JSDOM(`<div data-tab-panel="conversation"><div data-reply-shell>
    <main><form data-reply-composer><textarea data-reply-body></textarea></form></main>
    <aside><details data-assist-rail data-preference-key="${key}" open><summary>Assist</summary>
    <section data-ai-assist data-task="reply" data-workspace="conversation" data-mode="${options.mode||'manual'}"
      data-status-url="/assist/reply" data-ensure-url="/assist/reply" data-use-url="/assist/reply/use"
      data-initial="${attr(state)}" data-copy="${attr(copy)}">
      <p data-ai-message>Ready</p><div data-ai-output></div><p data-ai-cooldown></p>
      <form data-ai-form><input name="_token" value="synthetic"><button data-ai-prepare>Refresh</button></form>
    </section></details></aside></div></div>`,{url:'https://support.example.test',runScripts:'outside-only',pretendToBeVisual:true});
  t.after(()=>dom.window.close());
  const {window}=dom;const requests=[],timers=[];let submits=0;
  window.matchMedia=()=>({matches:options.wide!==false});
  if(options.saved)window.localStorage.setItem(key,options.saved);
  if(options.noStorage)Object.defineProperty(window,'localStorage',{get(){throw new Error('Storage denied');}});
  window.setInterval=fn=>timers.push(fn);
  window.fetch=(url,options)=>new Promise(resolve=>requests.push({url:String(url),options,resolve}));
  window.document.addEventListener('submit',event=>{event.preventDefault();submits++;});
  const load=name=>fs.readFileSync(path.join(root,'apps/server/resources/views/agent/conversations/partials',name),'utf8').replace(/<\/?script>/g,'');
  const railSource=fs.readFileSync(process.env.ASSIST_RAIL_SCRIPT_COPY||path.join(root,'apps/server/resources/views/agent/conversations/partials/assist-rail-script.blade.php'),'utf8').replace(/<\/?script>/g,'');
  window.eval(railSource);window.eval(load('ai-assist-script.blade.php'));
  const rail=window.document.querySelector('[data-assist-rail]'),body=window.document.querySelector('[data-reply-body]');
  return {window,rail,body,requests,timers,submits:()=>submits,toggle(open){rail.open=open;rail.dispatchEvent(new window.Event('toggle'));}};
}
function respond(request,payload=state){request.resolve({ok:true,status:200,headers:{get:()=>null},json:async()=>payload});}

test('the rail defaults open on desktop and collapsed on narrow screens without persisting the default',async t=>{
  const desktop=fixture(t),mobile=fixture(t,{wide:false});
  assert.equal(desktop.rail.open,true);
  assert.equal(mobile.rail.open,false,'A narrow workspace must default to a compact Assist disclosure');
  await flush();
  assert.equal(mobile.window.localStorage.getItem(key),null,'A responsive default must not overwrite the agent’s deliberate preference');
});

test('a deliberate rail preference survives another mount and overrides the responsive default',t=>{
  const first=fixture(t);first.toggle(false);
  assert.equal(first.window.localStorage.getItem(key),'closed','A deliberate collapse must be saved for the current agent');
  const next=fixture(t,{saved:first.window.localStorage.getItem(key)});assert.equal(next.rail.open,false,'A saved collapse must survive another workspace mount');
  const mobile=fixture(t,{wide:false,saved:'open'});assert.equal(mobile.rail.open,true,'A saved expand must override the narrow default');
});

test('blocked browser storage leaves the native disclosure usable',t=>{
  const f=fixture(t,{noStorage:true});assert.equal(f.rail.open,true);assert.doesNotThrow(()=>f.toggle(false));assert.equal(f.rail.open,false);
});

test('rail collapse and expansion preserve the draft and never admit another Prepare open',async t=>{
  const f=fixture(t,{mode:'prepare'});assert.equal(f.requests.length,1);respond(f.requests[0],{...state,mode:'prepare'});await flush();
  f.body.value='A human draft in progress.';f.body.dispatchEvent(new f.window.Event('input',{bubbles:true}));f.body.focus();
  f.toggle(false);f.toggle(true);f.timers.forEach(fn=>fn());await flush();
  assert.equal(f.requests.filter(r=>r.options.method==='POST').length,1,'Rail toggles must never create another preparation open');
  assert.equal(f.body.value,'A human draft in progress.');assert.equal(f.window.document.activeElement,f.body);assert.equal(f.submits(),0);
});

test('a suggestion in the right rail freshly inserts into the ordinary composer outside it without sending',async t=>{
  const f=fixture(t);respond(f.requests[0]);await flush();
  const use=f.rail.querySelector('[data-ai-use]');assert.ok(use&&!use.disabled);use.dispatchEvent(new f.window.MouseEvent('click',{bubbles:true}));
  const fresh=f.requests.find(r=>r.url.includes('/use?'));assert.ok(fresh,'Rail insertion must retain its fresh authorized Use request');respond(fresh);await flush();
  assert.equal(f.body.value,'Approved private reply.','The rail must find the ordinary composer through their shared workspace');assert.equal(f.window.document.activeElement,f.body);assert.equal(f.submits(),0);
  assert.equal(f.requests.filter(r=>r.options.method==='POST').length,0);
});
