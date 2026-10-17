const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const test = require('node:test');
const root = process.env.ASSIST_REVIEW_REPO_ROOT || path.resolve(__dirname, '../../../..');
const partials = process.env.ASSIST_REVIEW_SCRIPT_ROOT || path.join(root, 'apps/server/resources/views/agent/conversations/partials');
const {JSDOM} = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const generation = '2f07b4b0-6c86-49c9-aef7-b63498df8395', revision = 'a'.repeat(64);
const original = '  Human draft.\nExact <original> & spacing.  ';
const candidate = 'Fresh server-approved text.';
const flush = () => new Promise(resolve => setImmediate(resolve));
const attr = value => JSON.stringify(value).replaceAll('&','&amp;').replaceAll('"','&quot;');
const copy = {source_new_tab:'Opens in a new tab',states:{ready:'Ready',stale:'Stale',unavailable:'Unavailable'},use:'Insert',use_article:'Insert excerpt',refresh:'Refresh',retry:'Retry',cooldown:'Wait',used:'Added',blocked:'Edits preserved'};
const reviewCopy = {heading:'Review replacement',original:'Original',candidate:'Suggested',apply:'Apply',cancel:'Cancel',invalid:'Changed. Start a new preview.',unavailable:'Unavailable.',previewed:'Preview ready.',used:'Inserted.',cancelled:'Canceled.'};
const ready = task => ({mode:'manual',state:'ready',generation,policy_revision:'policy',source_position:12,source_url:'/dashboard/conversations/WF-TEST#conversation-message-12',source_ordinal:1,source_message_count:1,output_generation:generation,can_use:true,can_prepare:true,retry_after:0,
  output:task==='reply'?{text:candidate}:{articles:[{id:42,revision,title:'Account <article>',snippet:candidate,source_url:'/dashboard/articles/42'}]}});
const script = name => fs.readFileSync(path.join(partials,name),'utf8').replace(/<\/?script>/g,'').replace(/@json\(__\('([^']+)'\)\)/g,(_,key)=>JSON.stringify(key));
function markup(kind,task,state=ready(task)) {
  if(kind==='prepared') return `<section data-ai-assist data-task="${task}" data-workspace="conversation" data-mode="manual" data-status-url="/status" data-use-url="/use" data-ensure-url="/prepare" data-initial="${attr(state)}" data-copy="${attr(copy)}"><p data-ai-message></p><div data-ai-output></div><p data-ai-cooldown></p><form data-ai-form><input name="_token" value="synthetic"><button data-ai-prepare>Refresh</button></form></section>`;
  const suffix=task==='reply'?'reply-draft':'knowledge-suggestion';
  const output=task==='reply'?`<p data-copilot-reply-draft-content>Cached draft.</p><button type="button" data-copilot-reply-draft-use>Insert</button>`:`<article data-copilot-knowledge-article data-article-id="42" data-article-revision="${revision}"><p data-copilot-knowledge-snippet>Cached snippet.</p><button type="button" data-copilot-knowledge-use>Insert</button></article>`;
  return `<div data-copilot-${suffix} data-state="ready" data-generation="${state.generation}" data-policy-revision="${state.policy_revision}" data-source-position="12" data-use-url="/use" data-status-url="/status"><div data-copilot-${suffix}-stale hidden></div>${output}<p data-copilot-knowledge-status></p></div>`;
}
async function fixture(t,kind='prepared',task='reply',options={}) {
  const region=options.noRegion?'':`<section data-assist-draft-review data-copy="${attr(reviewCopy)}" hidden><h3 data-assist-review-heading tabindex="-1">Review</h3><pre data-assist-review-original></pre><pre data-assist-review-candidate></pre><p data-assist-review-status></p><button type="button" data-assist-review-apply>Apply</button><button type="button" data-assist-review-cancel>Cancel</button></section>`;
  const dom=new JSDOM(`<div data-tab-panel="conversation"><div data-reply-shell><form data-reply-composer><textarea data-reply-body lang="de"></textarea><select data-template-picker><option value="">Custom</option><option value="chosen" selected>Chosen template</option></select><p data-reply-status></p><button type="submit" data-reply-submit>Send</button></form><details data-assist-rail open><summary>Assist</summary><section data-assist-section="${task}">${markup(kind,task)}</section>${region}</details></div></div>`,{url:'https://support.example.test/dashboard/conversations/WF-TEST',runScripts:'outside-only',pretendToBeVisual:true});
  t.after(()=>dom.window.close());
  const {window}=dom,requests=[],timers=[],announcements=[],taskEvents=[];let sends=0;
  window.setTimeout=fn=>timers.push(fn);window.setInterval=fn=>timers.push(fn);
  window.fetch=(url,options)=>new Promise(resolve=>requests.push({url:String(url),options,resolve}));
  window.addEventListener('wayfindr:assist-action',event=>announcements.push(event.detail.message));
  window.document.addEventListener('wayfindr:assist-task-updated',event=>taskEvents.push(event.detail));
  window.document.addEventListener('submit',event=>{event.preventDefault();if(event.target.matches('[data-reply-composer]'))sends++;});
  window.eval(script('assist-draft-review-script.blade.php'));
  window.eval(script(kind==='prepared'?'ai-assist-script.blade.php':`copilot-${task==='reply'?'reply-draft':'knowledge-suggestion'}-script.blade.php`));
  const respond=(request,payload,status=200)=>request.resolve({ok:status>=200&&status<300,status,headers:{get:()=>null},json:async()=>payload,text:async()=>payload});
  if(kind==='prepared'){requests.filter(r=>r.url==='/status').forEach(r=>respond(r,ready(task)));await flush();}
  const shell=window.document.querySelector('[data-reply-shell]'),body=shell.querySelector('[data-reply-body]');body.value=options.empty?'':original;
  const panel=()=>shell.querySelector(kind==='prepared'?'[data-ai-assist]':`[data-copilot-${task==='reply'?'reply-draft':'knowledge-suggestion'}]`);
  const button=()=>panel()?.querySelector(kind==='prepared'?'[data-ai-use]':`[data-copilot-${task==='reply'?'reply-draft-use':'knowledge-use'}]`);
  const uses=()=>requests.filter(r=>r.url.startsWith('/use?'));
  const payload=()=>kind==='prepared'?ready(task):{generation,policy_revision:'policy',text:candidate,...(task==='knowledge'?{article_id:42}:{})};
  return {window,shell,body,panel,button,requests,timers,announcements,taskEvents,respond,uses,payload,sends:()=>sends,
    region:shell.querySelector('[data-assist-draft-review]'),apply:shell.querySelector('[data-assist-review-apply]'),cancel:shell.querySelector('[data-assist-review-cancel]'),
    edit(value){body.value=value;body.dispatchEvent(new window.Event('input',{bubbles:true}));},
    async preview(){button().click();respond(uses().at(-1),payload());await flush();},
    replace(change={}) {const old=panel(),next=old.cloneNode(true);if(kind==='prepared'){const value={...ready(task),...change};next.dataset.initial=JSON.stringify(value);}else{if(change.generation)next.dataset.generation=change.generation;if(change.policy_revision)next.dataset.policyRevision=change.policy_revision;if(change.can_use===false)next.dataset.state='stale';}old.replaceWith(next);return next;}
  };
}
for(const kind of ['prepared','legacy'])for(const task of ['reply','knowledge']) {
  test(`${kind} ${task}: Preview and Cancel preserve the exact draft and return to the current Insert control`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();
    assert.equal(f.body.value,original);assert.equal(f.body.getAttribute('lang'),'de');assert.equal(f.shell.querySelector('[data-template-picker]').value,'chosen');
    assert.equal(f.region.hidden,false);assert.equal(f.region.querySelector('[data-assist-review-original]').textContent,original);assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,candidate);
    assert.equal(f.window.document.activeElement,f.region.querySelector('[data-assist-review-heading]'));
    f.replace();await flush();f.cancel.click();assert.equal(f.body.value,original);assert.equal(f.region.hidden,true);assert.equal(f.window.document.activeElement,f.button());assert.equal(f.sends(),0);
    assert.deepEqual(f.announcements,['Preview ready.','Canceled.']);
  });
  test(`${kind} ${task}: Apply reauthorizes the previewed candidate and never sends`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();f.apply.click();f.apply.click();
    assert.equal(f.uses().length,2,'Apply must perform exactly one second fresh Use request');assert.equal(f.body.value,original);
    f.respond(f.uses()[1],f.payload());await flush();assert.equal(f.body.value,candidate);assert.equal(f.region.hidden,true);assert.equal(f.window.document.activeElement,f.body);assert.equal(f.sends(),0);
    assert.equal(f.shell.querySelector('[data-template-picker]').value,'');assert.equal(f.body.getAttribute('lang'),'');
    assert.ok(f.uses().every(r=>r.options.method===undefined&&r.options.cache==='no-store'&&!r.options.body));assert.ok(f.uses().every(r=>!r.url.includes('Human')&&!r.url.includes('Fresh')));
  });
  test(`${kind} ${task}: typing then deleting rejects both Preview and Apply responses`,async t=>{
    const f=await fixture(t,kind,task);f.button().click();f.edit('A human edit.');f.edit(original);f.respond(f.uses()[0],f.payload());await flush();assert.equal(f.body.value,original);assert.equal(f.apply.disabled,true);
    await f.preview();f.apply.click();f.edit('Another edit.');f.edit(original);f.respond(f.uses().at(-1),f.payload());await flush();assert.equal(f.body.value,original);assert.equal(f.apply.disabled,true);assert.equal(f.sends(),0);
  });
  test(`${kind} ${task}: unchanged native replacements preserve a review through fresh Apply`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();f.replace();await flush();assert.equal(f.apply.disabled,false,'Unchanged logical identity must survive DOM replacement');f.apply.click();f.respond(f.uses().at(-1),f.payload());await flush();assert.equal(f.body.value,candidate);
  });
  for(const change of ['generation','policy','permission','removal'])test(`${kind} ${task}: ${change} withdraws the private candidate and preserves the draft`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();
    if(change==='removal')f.panel().remove();else f.replace(change==='generation'?{generation:'another-generation'}:change==='policy'?{policy_revision:'changed'}:{can_use:false});
    await flush();assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'','Revoked or changed output must not remain readable');assert.equal(f.apply.disabled,true);assert.equal(f.body.value,original);
  });
  test(`${kind} ${task}: source changes immediately withdraw the candidate`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();f.window.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-source-changed'));assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.body.value,original);
  });
  test(`${kind} ${task}: denied Apply withdraws the candidate after its second authorization request`,async t=>{
    const f=await fixture(t,kind,task);await f.preview();f.replace();await flush();f.apply.click();assert.equal(f.uses().length,2);f.respond(f.uses()[1],{},403);await flush();assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.body.value,original);assert.equal(f.panel(),null,'A denial must withdraw the current logical card, even before replacement initialization');
  });
  test(`${kind} ${task}: Cancel defeats late responses and Apply rejects a different candidate`,async t=>{
    const f=await fixture(t,kind,task);f.button().click();f.cancel.click();f.respond(f.uses()[0],f.payload());await flush();assert.equal(f.region.hidden,true);assert.equal(f.body.value,original);
    await f.preview();f.apply.click();const value=f.payload();if(kind==='prepared'){if(task==='reply')value.output.text='Different text.';else value.output.articles[0].snippet='Different text.';}else value.text='Different text.';f.respond(f.uses().at(-1),value);await flush();assert.equal(f.body.value,original);assert.equal(f.apply.disabled,true);assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');
  });
  test(`${kind} ${task}: empty composers retain one fresh request and ordinary insertion`,async t=>{
    const f=await fixture(t,kind,task,{empty:true});f.button().click();f.respond(f.uses()[0],f.payload());await flush();assert.equal(f.body.value,candidate);assert.equal(f.uses().length,1);assert.equal(f.region.hidden,true);assert.equal(f.sends(),0);
  });
}
for(const kind of ['prepared','legacy']) {
  test(`${kind}: selected article revision changes withdraw the candidate`,async t=>{
    const f=await fixture(t,kind,'knowledge');await f.preview();const next=f.replace();if(kind==='prepared'){const value=JSON.parse(next.dataset.initial);value.output.articles[0].revision='b'.repeat(64);next.dataset.initial=JSON.stringify(value);}else next.querySelector('[data-copilot-knowledge-article]').dataset.articleRevision='b'.repeat(64);await flush();assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.apply.disabled,true);assert.equal(f.body.value,original);
  });
}
test('template changes, read-only and sending invalidate reviews without restoring over later text',async t=>{
  const f=await fixture(t);await f.preview();f.body.value='Template replacement.';f.shell.querySelector('[data-template-picker]').dispatchEvent(new f.window.Event('change',{bubbles:true}));assert.equal(f.apply.disabled,true);f.cancel.click();assert.equal(f.body.value,'Template replacement.');
  await f.preview();f.body.readOnly=true;await flush();assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');f.body.readOnly=false;
  await f.preview();f.shell.querySelector('[data-reply-composer]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));assert.equal(f.region.querySelector('[data-assist-review-candidate]').textContent,'');assert.equal(f.body.value,'Template replacement.');
});
test('a superseding preview defeats an earlier response and rail collapse leaves the draft and review intact',async t=>{
  const f=await fixture(t);f.button().click();const earlier=f.uses()[0];f.edit('Newer human draft.');f.button().click();assert.equal(f.uses().length,2);f.respond(f.uses()[1],f.payload());await flush();f.respond(earlier,f.payload());await flush();assert.equal(f.region.querySelector('[data-assist-review-original]').textContent,'Newer human draft.');
  const requests=f.requests.length;f.shell.querySelector('[data-assist-rail]').open=false;f.shell.querySelector('[data-assist-rail]').open=true;await flush();assert.equal(f.requests.length,requests);assert.equal(f.apply.disabled,false);assert.equal(f.body.value,'Newer human draft.');
});
test('missing review markup cannot overwrite a draft while empty insertion remains available',async t=>{
  const dirty=await fixture(t,'prepared','reply',{noRegion:true});dirty.button().click();assert.equal(dirty.uses().length,0);assert.equal(dirty.body.value,original);
  const empty=await fixture(t,'prepared','reply',{noRegion:true,empty:true});empty.button().click();empty.respond(empty.uses()[0],empty.payload());await flush();assert.equal(empty.body.value,candidate);
});
test('prepared source links use text APIs and only same-origin authorized URLs',async t=>{
  const f=await fixture(t,'prepared','knowledge');const link=f.panel().querySelector('[data-ai-output] a');assert.equal(link.getAttribute('href'),'https://support.example.test/dashboard/articles/42');assert.equal(link.textContent,'Account <article>');assert.equal(link.querySelector('article'),null);
  const next=f.replace();const value=JSON.parse(next.dataset.initial);value.output.articles[0].source_url='https://outside.example/steal';next.dataset.initial=JSON.stringify(value);f.timers.forEach(fn=>fn());assert.equal(next.querySelector('[data-ai-output] a'),null);
});

test('prepared task updates publish provenance datasets and a bounded event without output text',async t=>{
  const f=await fixture(t);const data=f.panel().dataset;
  assert.equal(data.generation,generation);assert.equal(data.policyRevision,'policy');assert.equal(data.sourcePosition,'12');assert.equal(data.sourceOrdinal,'1');assert.equal(data.sourceMessageCount,'1');assert.equal(data.outputGeneration,generation);assert.equal(data.sourceUrl,'/dashboard/conversations/WF-TEST#conversation-message-12');
  assert.ok(f.taskEvents.length>0);for(const event of f.taskEvents){assert.deepEqual(Object.keys(event).sort(),['state','task','workspace']);assert.equal(event.task,'reply');assert.equal(JSON.stringify(event).includes(candidate),false);}
});
for(const source of ['https://outside.example/article','javascript:alert(1)','https://someone:secret@support.example.test/article','', 'https://support.example.test:444/article'])test(`prepared links reject unsafe or external source ${JSON.stringify(source)}`,async t=>{
  const f=await fixture(t,'prepared','knowledge');const next=f.replace();const value=JSON.parse(next.dataset.initial);value.output.articles[0].source_url=source;next.dataset.initial=JSON.stringify(value);f.timers.forEach(fn=>fn());assert.equal(next.querySelector('[data-ai-output] a'),null);
});

test('source inspection opens a labelled independent tab while the unsent composer remains in place',async t=>{
  const f=await fixture(t,'prepared','knowledge');const link=f.panel().querySelector('[data-ai-output] a');
  assert.equal(link.target,'_blank','Source inspection must preserve the original conversation document and draft');assert.equal(link.rel,'noopener');assert.match(link.getAttribute('aria-label'),/Opens in a new tab/);assert.equal(f.body.value,original);assert.equal(f.sends(),0);
});
