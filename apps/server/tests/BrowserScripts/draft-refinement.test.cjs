const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const test = require('node:test');
const root = process.env.REFINEMENT_REPO_ROOT || path.resolve(__dirname, '../../../..');
const partials = process.env.REFINEMENT_SCRIPT_ROOT || path.join(root, 'apps/server/resources/views/agent/conversations/partials');
const {JSDOM} = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const original = '  It may take 3 days.\nExact <original> & spacing.  ';
const candidate = '  It might take 3 days.\n<literal> proposal.  ';
const generation = '22222222-2222-4222-8222-222222222222';
const copy = {preparing:'Preparing private preview.',stale:'Changed; current text preserved.',cancelled:'Cancelled; draft preserved.',unavailable:'Unavailable; draft preserved.',applied:'Applied; Send is separate.',cooldown:'Wait before retrying.',review_help:'Review facts before Apply.',sources:'Published guidance used'};
const reviewCopy = {previewed:'Preview ready.',used:'Inserted.',cancelled:'Preview cancelled.',invalid:'Changed; start a new preview.',unavailable:'Preview unavailable.'};
const attr = value => JSON.stringify(value).replaceAll('&','&amp;').replaceAll('"','&quot;');
const flush = () => new Promise(resolve => setImmediate(resolve));
const script = name => fs.readFileSync(path.join(partials,name+'.blade.php'),'utf8').replace(/<\/?script>/g,'');

async function fixture(t, {available=true, sources=[]}={}) {
  const dom = new JSDOM(`<main data-workspace><div data-reply-shell>
    <form data-reply-composer><input name="_token" value="synthetic-csrf"><textarea data-reply-body lang="de"></textarea>
    <select data-template-picker><option value="">Custom</option><option value="chosen" selected>Chosen template</option></select>
    <section data-draft-refinement data-available="${available}" data-authorize-url="/refine/authorize" data-request-url="/refine" data-status-url="/refine/status" data-apply-url="/refine/apply" data-cancel-url="/refine/cancel" data-copy="${attr(copy)}">
    <select data-refinement-action><option value="clarify">Clarify</option><option value="shorten">Shorten</option><option value="tone">Tone</option><option value="translate">Translate</option></select>
    <div data-refinement-tone-field hidden><select data-refinement-tone><option value="calm">Calm</option><option value="friendly">Friendly</option><option value="professional">Professional</option></select></div>
    <div data-refinement-language-field hidden><select data-refinement-language><option value="en">English</option><option value="de">Deutsch</option><option value="it">Italiano</option></select></div>
    <button type="button" data-refinement-request disabled>Preview</button><button type="button" data-refinement-cancel hidden>Cancel</button><p data-refinement-status></p></section>
    <button type="submit" data-reply-submit>Send</button></form>
    <aside><details data-assist-rail open><summary>Assist</summary>
    <section data-assist-draft-review data-copy="${attr(reviewCopy)}" hidden><h3 data-assist-review-heading tabindex="-1">Review</h3><pre data-assist-review-original></pre><pre data-assist-review-candidate></pre><div data-assist-review-sources hidden></div><p data-assist-review-status></p><button type="button" data-assist-review-apply>Apply</button><button type="button" data-assist-review-cancel>Cancel</button></section>
    </details></aside></div></main>`, {url:'https://support.example.test/dashboard/conversations/WF-SYNTHETIC',runScripts:'outside-only',pretendToBeVisual:true});
  t.after(() => dom.window.close());
  const {window} = dom, requests=[], timers=[], announcements=[], feedbackUses=[], feedbackViews=[]; window.wayfindrAssistFeedback={used:(...args)=>feedbackUses.push(args),viewed:(...args)=>feedbackViews.push(args)};
  let time=0, uuid=0, sends=0, storageWrites=0;
  Object.defineProperty(window.performance,'now',{value:()=>time});
  Object.defineProperty(window.crypto,'randomUUID',{value:()=>`11111111-1111-4111-8111-${String(++uuid).padStart(12,'0')}`});
  window.setTimeout=(fn,delay)=>{const timer={id:timers.length+1,fn,delay,cleared:false};timers.push(timer);return timer.id;};
  window.clearTimeout=id=>{const timer=timers.find(timer=>timer.id===id);if(timer)timer.cleared=true;};
  window.Storage.prototype.setItem=()=>{storageWrites++;throw new Error('Private text must not enter browser storage.');};
  window.fetch=(url,options={})=>new Promise((resolve,reject)=>{
    const request={url:new URL(String(url),window.location.href),options,resolve,reject,settled:false};requests.push(request);
    options.signal?.addEventListener('abort',()=>{if(!request.settled){request.settled=true;reject(new Error('aborted'));}});
  });
  const respond=(request,value,status=200,headers={})=>{assert.ok(request,'Expected a request');assert.equal(request.settled,false);request.settled=true;request.resolve({ok:status>=200&&status<300,status,headers:{get:key=>headers[key]??null},json:async()=>value});};
  window.addEventListener('wayfindr:assist-action',event=>announcements.push(event.detail.message));
  window.document.addEventListener('submit',event=>{event.preventDefault();if(event.target.matches('[data-reply-composer]'))sends++;});
  const shell=window.document.querySelector('[data-reply-shell]'), body=shell.querySelector('[data-reply-body]');body.value=original;
  window.eval(script('assist-draft-review-script'));window.eval(script('draft-refinement-script'));await flush();
  const byPath=pathname=>requests.filter(request=>request.url.pathname===pathname);
  const pending = (id=generation) => ({generation:id,draft_revision:JSON.parse((byPath('/refine').at(-1)||byPath('/refine/authorize').at(-1)).options.body).draft_revision,policy_revision:'policy',state:'pending',expires_at:2000000000,ttl_seconds:900,preview:null,sources:[]});
  const authorized = () => ({...pending(),state:'authorized'});
  const ready = (changes={}) => ({...pending(),state:'ready',feedback_receipt:'private-apply-receipt',preview:{candidate,citations:sources.map(source=>source.ref),limitation:null},sources,...changes});
  const buttons={request:shell.querySelector('[data-refinement-request]'),cancel:shell.querySelector('[data-refinement-cancel]'),apply:shell.querySelector('[data-assist-review-apply]'),reviewCancel:shell.querySelector('[data-assist-review-cancel]')};
  const controls={action:shell.querySelector('[data-refinement-action]'),tone:shell.querySelector('[data-refinement-tone]'),language:shell.querySelector('[data-refinement-language]')};
  const change=(control,value)=>{control.value=value;control.dispatchEvent(new window.Event('change',{bubbles:true}));};
  return {window,shell,body,requests,timers,announcements,respond,byPath,pending,authorized,ready,buttons,controls,change,
    region:shell.querySelector('[data-assist-draft-review]'),status:shell.querySelector('[data-refinement-status]'),
    sends:()=>sends,feedbackUses:()=>feedbackUses,feedbackViews:()=>feedbackViews,storageWrites:()=>storageWrites,
    edit(value){body.value=value;body.dispatchEvent(new window.Event('input',{bubbles:true}));},
    async grant(){buttons.request.click();respond(byPath('/refine/authorize').at(-1),authorized(),201);await flush();},
    async start(){await this.grant();respond(byPath('/refine').at(-1),pending(),202);await flush();},
    async preview(){await this.start();respond(byPath('/refine/status').at(-1),ready());await flush();respond(byPath('/refine/status').at(-1),ready());await flush();},
    async tick(delay=2000){const timer=timers.find(timer=>!timer.cleared&&timer.delay===delay);assert.ok(timer,`Expected active ${delay}ms timer`);timer.cleared=true;time+=delay;timer.fn();await flush();},
    advance(ms){time+=ms;}
  };
}

test('opening the conversation sends no unsent text; explicit Preview sends only its bounded operation payload',async t=>{
  const f=await fixture(t);assert.equal(f.requests.length,0);await f.grant();
  const request=f.byPath('/refine')[0], payload=JSON.parse(request.options.body);
  assert.deepEqual(Object.keys(payload).sort(),['action','draft_revision','generation','language','refinement_draft','tone']);
  assert.equal(payload.refinement_draft,original);assert.equal(payload.action,'clarify');assert.equal(payload.tone,null);assert.equal(payload.language,null);
  assert.equal(request.options.headers['X-CSRF-TOKEN'],'synthetic-csrf');assert.equal(request.options.cache,'no-store');assert.equal(f.body.value,original);assert.equal(f.storageWrites(),0);assert.equal(f.sends(),0);
});

for(const [action,parameter,value] of [['shorten',null,null],['tone','tone','friendly'],['translate','language','de'],['translate','language','it']])test(`${action} ${value||''}: validated selections stay operation data`,async t=>{
  const f=await fixture(t);f.change(f.controls.action,action);if(parameter)f.change(f.controls[parameter],value);await f.start();
  const payload=JSON.parse(f.byPath('/refine')[0].options.body);assert.equal(payload.action,action);assert.equal(payload.tone,parameter==='tone'?value:null);assert.equal(payload.language,parameter==='language'?value:null);
  assert.equal(f.shell.querySelector('[data-refinement-tone-field]').hidden,action!=='tone');assert.equal(f.shell.querySelector('[data-refinement-language-field]').hidden,action!=='translate');
});

test('private preview and Apply preserve exact whitespace, reauthorize once, and never Send',async t=>{
  const f=await fixture(t);await f.preview();assert.equal(f.body.value,original);assert.equal(f.region.hidden,false);
  assert.equal(f.region.querySelector('[data-assist-review-original]').textContent,original);assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,candidate);
  assert.equal(f.window.document.activeElement,f.region.querySelector('h3'));f.buttons.apply.click();f.buttons.apply.click();assert.equal(f.byPath('/refine/apply').length,1);
  const request=f.byPath('/refine/apply')[0];assert.deepEqual(Object.keys(JSON.parse(request.options.body)).sort(),['draft_revision','generation']);
  f.respond(request,f.ready());await flush();assert.equal(f.body.value,candidate);assert.equal(f.region.hidden,true);assert.equal(f.window.document.activeElement,f.body);assert.equal(f.sends(),0);assert.equal(f.storageWrites(),0);
});

test('Cancel keeps exact original text, template and language, clears preview, and returns focus',async t=>{
  const f=await fixture(t);await f.preview();f.buttons.reviewCancel.click();await flush();
  assert.equal(f.body.value,original);assert.equal(f.body.getAttribute('lang'),'de');assert.equal(f.shell.querySelector('[data-template-picker]').value,'chosen');
  assert.equal(f.region.hidden,true);assert.equal(f.region.querySelector('[data-assist-review-original]').textContent,'');assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');
  assert.equal(f.byPath('/refine/cancel').length,1);assert.equal(f.window.document.activeElement,f.buttons.request);assert.equal(f.sends(),0);
});

test('Cancel while capture is awaiting acknowledgement fences the late receipt and sends identifiers only',async t=>{
  const f=await fixture(t);await f.grant();const first=f.byPath('/refine')[0],pending=f.pending();f.buttons.cancel.click();
  assert.equal(f.byPath('/refine/cancel').length,1,'The handle is already cancellable before capture acknowledges.');
  f.respond(first,pending,202);await flush();assert.equal(f.byPath('/refine/status').length,0);assert.equal(f.byPath('/refine/cancel').length,2);
  const cancellation=f.byPath('/refine/cancel')[0];assert.equal(cancellation.options.keepalive,true);assert.equal(cancellation.options.body.includes(original),false);assert.equal(f.body.value,original);assert.equal(f.region.hidden,true);
});

for(const phase of ['preparing','preview','apply'])test(`edits during ${phase} preserve intervening text and refuse late replacement`,async t=>{
  const f=await fixture(t);if(phase==='preparing')await f.start();else await f.preview();
  if(phase==='apply')f.buttons.apply.click();const pending=f.byPath(phase==='apply'?'/refine/apply':'/refine/status').at(-1);
  f.edit('New human words.');if(!pending.settled)f.respond(pending,f.ready());await flush();assert.equal(f.body.value,'New human words.');assert.equal(f.region.hidden,true);assert.equal(f.sends(),0);
  assert.equal(f.byPath('/refine/apply').length,phase==='apply'?1:0);
});

test('typing and deleting back to the original still invalidates the draft revision',async t=>{
  const f=await fixture(t);await f.preview();f.edit(original+'x');f.edit(original);f.buttons.apply.click();await flush();
  assert.equal(f.body.value,original);assert.equal(f.byPath('/refine/apply').length,0);assert.equal(f.region.hidden,true);
});

for(const event of ['wayfindr:assist-source-changed','offline','online','pagehide','pageshow'])test(`${event} withdraws private preview and never restores an old draft`,async t=>{
  const f=await fixture(t);await f.preview();f.window.dispatchEvent(new f.window.Event(event));await flush();assert.equal(f.body.value,original);assert.equal(f.region.hidden,true);assert.equal(f.byPath('/refine/cancel').length,1);assert.equal(f.sends(),0);
});

for(const end of ['hidden','removed','readonly','disabled','submitting'])test(`workspace becomes ${end}: private content is withdrawn and Apply stays unavailable`,async t=>{
  const f=await fixture(t);await f.preview();
  if(end==='hidden')f.shell.parentElement.hidden=true;else if(end==='removed')f.shell.remove();else if(end==='submitting')f.body.closest('form').dataset.submitting='true';else f.body[end==='readonly'?'readOnly':'disabled']=true;
  await flush();assert.equal(f.body.value,original);assert.equal(f.region.hidden,true);assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.byPath('/refine/cancel').length,1);
});

test('server source withdrawal removes a visible candidate and cited passage on the next private poll',async t=>{
  const source={ref:'src-'+'a'.repeat(24),title:'Guide <img src=x>',passage:'Published <script>untrusted()</script> text.'};
  const f=await fixture(t,{sources:[source]});await f.preview();const details=f.region.querySelector('details');assert.equal(details.open,false);assert.equal(details.querySelector('blockquote').textContent,source.passage);assert.equal(details.querySelector('script'),null);assert.equal(details.querySelector('img'),null);
  await f.tick();f.respond(f.byPath('/refine/status').at(-1),f.ready({state:'stale',preview:null,sources:[]}));await flush();assert.equal(f.region.hidden,true);assert.equal(f.region.querySelector('[data-assist-review-sources]').textContent,'');assert.equal(f.body.value,original);
});

for(const change of ['generation','policy_revision','candidate'])test(`a changed ${change} at Apply never replaces the exact original`,async t=>{
  const f=await fixture(t);await f.preview();f.buttons.apply.click();const value=f.ready();if(change==='candidate')value.preview.candidate='Different proposal';else value[change]='changed';
  f.respond(f.byPath('/refine/apply')[0],value);await flush();assert.equal(f.body.value,original);assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.sends(),0);
});

test('preview expiry uses a bounded monotonic lifetime even with an unrelated wall clock',async t=>{
  const f=await fixture(t);f.window.Date.now=()=>1;await f.preview();await f.tick(900000);assert.equal(f.region.hidden,true);assert.equal(f.body.value,original);assert.equal(f.byPath('/refine/cancel').length,1);
});

test('budget/cooldown failure preserves draft and enables retry after its bounded delay',async t=>{
  const f=await fixture(t);await f.grant();f.respond(f.byPath('/refine')[0],{state:'unavailable',failure_code:'usage_request_limit'},429,{'Retry-After':'60'});await flush();
  assert.equal(f.body.value,original);assert.equal(f.buttons.request.disabled,true);assert.equal(f.status.textContent,copy.cooldown);await f.tick(60000);assert.equal(f.buttons.request.disabled,false);assert.equal(f.sends(),0);
});

test('ordinary Send remains independent while a refinement is pending',async t=>{
  const f=await fixture(t);await f.start();f.body.closest('form').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));await flush();assert.equal(f.sends(),1);assert.equal(f.body.value,original);assert.equal(f.byPath('/refine/cancel').length,1);
});

test('disabled AI never starts work and does not disable ordinary Send',async t=>{
  const f=await fixture(t,{available:false});assert.equal(f.buttons.request.disabled,true);f.buttons.request.click();assert.equal(f.requests.length,0);assert.equal(f.shell.querySelector('[data-reply-submit]').disabled,false);
});

for(const draft of ['',' \u00a0\u2003','\u200b\ufeff','\u0080','Real\u009bcontrol','\ud800','x'.repeat(4001)])test(`blank unsafe or overlong input never starts capture ${JSON.stringify(draft.slice(0,12))}`,async t=>{
  const f=await fixture(t);f.edit(draft);await flush();assert.equal(f.buttons.request.disabled,true);f.buttons.request.click();assert.equal(f.requests.length,0);
});

test('explicit private Preview opens a collapsed Assist rail without sending or trimming the original',async t=>{
  const f=await fixture(t);f.shell.querySelector('[data-assist-rail]').open=false;await f.preview();assert.equal(f.shell.querySelector('[data-assist-rail]').open,true);assert.equal(f.body.value,original);assert.equal(f.sends(),0);
});

test('callbacks after the final Apply assignment can make later edits without being overwritten',async t=>{
  const f=await fixture(t);await f.preview();f.shell.querySelector('[data-template-picker]').addEventListener('change',()=>{f.body.value='A later programmatic edit.';});
  f.buttons.apply.click();f.respond(f.byPath('/refine/apply')[0],f.ready());await flush();assert.equal(f.body.value,'A later programmatic edit.');assert.equal(f.sends(),0);
});

for (const result of ['consumed', 'read-failed']) test(`an already-running status poll (${result}) cannot revoke an authorized Apply`,async t=>{
  const f=await fixture(t);await f.preview();await f.tick();
  const poll=f.byPath('/refine/status').at(-1);f.buttons.apply.click();
  const apply=f.byPath('/refine/apply').at(-1);
  assert.equal(f.timers.filter(timer=>!timer.cleared&&timer.delay===2000).length,0,'Apply pauses future polls.');
  f.respond(poll,f.ready({state:'applied',preview:null,sources:[]}),result==='read-failed'?503:200);await flush();
  f.respond(apply,f.ready());await flush();
  assert.equal(f.body.value,candidate,'The authorized Apply must survive the consumed or failed status receipt.');
  assert.equal(f.region.hidden,true);assert.equal(f.sends(),0);
});


test('grant handshake sends only a revision before any unsent text leaves the browser',async t=>{
  const f=await fixture(t);f.buttons.request.click();
  assert.equal(f.byPath('/refine').length,0);
  const grant=f.byPath('/refine/authorize')[0];
  assert.deepEqual(Object.keys(JSON.parse(grant.options.body)),['draft_revision']);
  assert.equal(grant.options.body.includes(original),false);
});

for(const phase of ['grant','capture'])for(const end of ['pagehide','hidden','removed','edit'])test(`${end} during ${phase} prevents unacknowledged draft work`,async t=>{
  const f=await fixture(t);
  if(phase==='grant')f.buttons.request.click();else await f.grant();
  const response=phase==='grant'?f.authorized():f.pending();
  const request=f.byPath(phase==='grant'?'/refine/authorize':'/refine')[0];
  if(end==='pagehide')f.window.dispatchEvent(new f.window.Event('pagehide'));
  else if(end==='hidden')f.shell.parentElement.hidden=true;
  else if(end==='removed')f.shell.remove();
  else f.edit('Human edited while waiting.');
  await flush();
  assert.equal(f.byPath('/refine/cancel').length,phase==='capture'?1:0,'A known handle is cancelled before any late ACK callback.');
  if(phase==='capture'){
    const cancel=f.byPath('/refine/cancel')[0];
    assert.equal(cancel.options.keepalive,true);
    assert.deepEqual(Object.keys(JSON.parse(cancel.options.body)).sort(),['draft_revision','generation']);
  }
  f.respond(request,response,phase==='grant'?201:202);await flush();
  assert.equal(f.byPath('/refine').length,phase==='grant'?0:1);
  assert.equal(f.byPath('/refine/status').length,0);
  assert.equal(f.body.value,end==='edit'?'Human edited while waiting.':original);
});

for(const invalid of ['failed','wrong-revision','wrong-state','wrong-generation'])test(`invalid ${invalid} grant never sends draft text`,async t=>{
  const f=await fixture(t);f.buttons.request.click();const grant=f.authorized();
  if(invalid==='wrong-revision')grant.draft_revision='changed';
  if(invalid==='wrong-state')grant.state='pending';
  if(invalid==='wrong-generation')grant.generation='invalid';
  f.respond(f.byPath('/refine/authorize')[0],grant,invalid==='failed'?503:201);await flush();
  assert.equal(f.byPath('/refine').length,0);assert.equal(f.body.value,original);
});

test('private preview records view only and the guarded Apply records use without Send',async t=>{
 const f=await fixture(t);await f.preview();assert.equal(f.feedbackViews().length,1);assert.equal(f.feedbackUses().length,0);
 f.buttons.apply.click();f.respond(f.byPath('/refine/apply').at(-1),f.ready());await flush();
 assert.equal(f.feedbackUses().length,1);assert.equal(f.feedbackUses()[0][2],'private-apply-receipt');assert.equal(f.sends(),0);
});
