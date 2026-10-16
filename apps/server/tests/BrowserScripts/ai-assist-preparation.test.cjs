const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const test = require('node:test');
const root = path.resolve(__dirname, '../../../..');
const {JSDOM} = createRequire(path.join(root, 'packages/widget-js/package.json'))('jsdom');
const generation = '2f07b4b0-6c86-49c9-aef7-b63498df8395';
const empty = {state:'empty', generation:null, policy_revision:'policy', output:null, can_use:false, can_prepare:true, retry_after:0};
const ready = task => ({...empty, state:'ready', generation, can_use:true, output:task === 'ticket'
  ? {title:'Fresh ticket title', priority:'high', label_ids:[8]}
  : task === 'knowledge' ? {articles:[{id:42,title:'Fresh article',snippet:'Fresh excerpt'}]} : {text:'Fresh approved reply'}});
const copy = {states:{empty:'Empty',preparing:'Preparing',ready:'Ready',stale:'Stale',unavailable:'Unavailable'},
  prepare:'Prepare',prepare_ticket:'Suggest ticket details',refresh:'Refresh',retry:'Retry',use:'Insert',use_article:'Insert excerpt',apply:'Apply',cooldown:'Wait :seconds',blocked:'Edits preserved',used:'Added',priorities:{high:'High'}};
const attr = value => JSON.stringify(value).replaceAll('&','&amp;').replaceAll('"','&quot;');
function page(t, options = {}) {
  let visibility = options.hidden ? 'hidden' : 'visible';
  let prerender = options.prerender || false;
  const panel = task => `<section data-ai-assist data-task="${task}" data-workspace="${task === 'ticket' ? 'conversion' : 'conversation'}"
    data-mode="${options.mode || 'prepare'}" data-status-url="/assist/${task}" data-ensure-url="/assist/${task}" data-use-url="/assist/${task}/use"
    data-initial="${attr(options.ready === task ? ready(task) : empty)}" data-copy="${attr(copy)}">
    <p data-ai-message></p><div data-ai-output></div><p data-ai-cooldown></p>
    <form data-ai-form><input name="_token" value="synthetic-csrf"><button data-ai-prepare type="submit">Prepare</button></form></section>`;
  const dom = new JSDOM(`<button data-ai-prepare-all>Prepare suggestions</button><div data-tab-panel="conversation" ${options.ticket ? 'hidden' : ''}><div data-reply-shell>
    <textarea data-reply-body></textarea><select data-template-picker><option value="">None</option></select>
    ${panel('summary')}${options.legacyReply ? '<section data-copilot-reply-draft></section>' : panel('reply')}${panel('knowledge')}</div></div>
    <div data-tab-panel="ticket" ${options.ticket ? '' : 'hidden'}>${panel('ticket')}
      <form data-ticket-creation-form data-ticket-suggestion-dirty="false"><input name="title" data-ticket-subject value="Default title">
        <select name="priority" data-ticket-priority><option value="normal">Normal</option><option value="high">High</option></select>
        <input type="checkbox" name="labels" value="8" data-ticket-label-id></form></div>`,
    {url:'https://support.example.test/dashboard/conversations/WF-TEST', runScripts:'outside-only', pretendToBeVisual:true});
  t.after(() => dom.window.close());
  const {window} = dom; const requests = [], timers = [];
  Object.defineProperty(window.document, 'visibilityState', {get:() => visibility});
  Object.defineProperty(window.document, 'prerendering', {get:() => prerender});
  window.setInterval = callback => {timers.push(callback);};
  window.fetch = (url, options) => new Promise(resolve => requests.push({url:String(url),options,resolve}));
  const source = fs.readFileSync(process.env.ASSIST_PREPARATION_SCRIPT_COPY || path.join(root,'apps/server/resources/views/agent/conversations/partials/ai-assist-script.blade.php'),'utf8').replace(/<\/?script>/g,'');
  window.eval(source);
  return {window,requests,timers,markup:panel,
    panel:task => window.document.querySelector(`[data-ai-assist][data-task="${task}"]`),
    body:window.document.querySelector('[data-reply-body]'), form:window.document.querySelector('[data-ticket-creation-form]'),
    activate(task) {window.document.querySelectorAll('[data-tab-panel]').forEach(p => {p.hidden = p.dataset.tabPanel !== task;});window.dispatchEvent(new window.CustomEvent('wayfindr:tab-shown'));},
    visibility(value) {visibility=value;window.document.dispatchEvent(new window.Event('visibilitychange'));},
    activatePrerender() {prerender=false;window.document.dispatchEvent(new window.Event('prerenderingchange'));}
  };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
const respond = (request,payload,status=200) => request.resolve({ok:status >=200 && status<300,status,headers:{get:()=>null},json:async()=>payload});
const posts = f => f.requests.filter(r=>r.options.method==='POST');
const uses = f => f.requests.filter(r=>r.url.includes('/use?'));
const edit = (f,field,value) => {field.value=value;field.dispatchEvent(new f.window.Event('input',{bubbles:true}));};

test('visible conversation opens POST its three tasks once; conversion waits for its own open', t => {
  const f=page(t);
  assert.deepEqual(posts(f).map(r=>r.url).sort(), ['/assist/knowledge','/assist/reply','/assist/summary']);
  f.window.dispatchEvent(new f.window.Event('pageshow'));
  assert.equal(posts(f).length,3,'Repeated visibility events must not invent another open');
  f.activate('ticket');
  assert.equal(posts(f).at(-1).url,'/assist/ticket');
  assert.deepEqual(JSON.parse(posts(f).at(-1).options.body),{action:'open',workspace:'conversion'});
});

test('background and prerendered pages never POST until activated', t => {
  const hidden=page(t,{hidden:true}); hidden.timers.forEach(fn=>fn());
  assert.equal(hidden.requests.length,0,'Hidden pages must not prepare or poll');
  hidden.visibility('visible'); assert.equal(posts(hidden).length,3);
  const prerender=page(t,{prerender:true}); assert.equal(prerender.requests.length,0,'Prerender must not prepare');
  prerender.activatePrerender(); assert.equal(posts(prerender).length,3);
});

test('Manual rendering and polling are read-only; explicit initial submit alone POSTs', async t => {
  const f=page(t,{mode:'manual'}); assert.equal(posts(f).length,0);
  f.requests.forEach(r=>respond(r,empty)); await flush();
  f.panel('reply').querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(posts(f).length,1);
  assert.equal(JSON.parse(posts(f)[0].options.body).action,'initial');
});

test('source notifications disable Use and only read state, never regenerate', async t => {
  const f=page(t,{mode:'manual',ready:'reply'});
  f.requests.forEach(r=>respond(r,r.url.endsWith('/reply') ? ready('reply') : empty)); await flush();
  f.window.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-source-changed'));
  assert.equal(f.panel('reply').querySelector('[data-ai-use]').disabled,true,'A new source event must disable insertion immediately');
  assert.equal(posts(f).length,0,'Source notifications must never create background regeneration');
});

for (const task of ['reply','knowledge']) {
  test(`${task} uses fresh authorized data and never sends the composer`, async t => {
    const f=page(t,{mode:'manual',ready:task});
    f.requests.forEach(r=>respond(r,r.url.endsWith('/'+task)?ready(task):empty)); await flush();
    f.panel(task).querySelector('[data-ai-use]').click();
    assert.equal(uses(f).length,1); assert.equal(uses(f)[0].options.method,undefined);
    respond(uses(f)[0],ready(task)); await flush();
    assert.equal(f.body.value,task==='reply'?'Fresh approved reply':'Fresh excerpt');
    assert.equal(posts(f).length,0,'Insertion must never send a customer reply');
  });
  test(`${task} preserves edits made and deleted during a Use fetch`, async t => {
    const f=page(t,{mode:'manual',ready:task});
    f.requests.forEach(r=>respond(r,r.url.endsWith('/'+task)?ready(task):empty)); await flush();
    f.panel(task).querySelector('[data-ai-use]').click();
    edit(f,f.body,'A human edit'); edit(f,f.body,'');
    respond(uses(f)[0],ready(task)); await flush();
    assert.equal(f.body.value,'','An edit revision must block insertion even when the field returns to empty');
  });
}

test('Prepare conversion waits for deliberate Apply and never submits the ticket', async t => {
  const f=page(t,{ticket:true}); let submits=0;
  f.form.addEventListener('submit',()=>submits++);
  respond(posts(f)[0],ready('ticket')); await flush();
  assert.equal(uses(f).length,0,'Generation must never apply ticket fields automatically');
  assert.equal(f.form.querySelector('[data-ticket-subject]').value,'Default title');
  f.panel('ticket').querySelector('[data-ai-use]').click();
  assert.equal(uses(f).length,1); respond(uses(f)[0],ready('ticket')); await flush();
  assert.equal(f.form.querySelector('[data-ticket-subject]').value,'Fresh ticket title');
  assert.equal(f.form.querySelector('[data-ticket-priority]').value,'high');
  assert.equal(f.form.dataset.ticketSuggestionDirty,'true'); assert.equal(submits,0);
});

test('conversion keeps a human edit made while fresh Use is in flight', async t => {
  const f=page(t,{ticket:true}); respond(posts(f)[0],ready('ticket')); await flush();
  f.panel('ticket').querySelector('[data-ai-use]').click();
  const title=f.form.querySelector('[data-ticket-subject]'); edit(f,title,'My title');
  respond(uses(f)[0],ready('ticket')); await flush();
  assert.equal(title.value,'My title','Applying ticket details must not overwrite edits');
});

test('Manual conversion never auto-applies even when Ready', async t => {
  const f=page(t,{mode:'manual',ticket:true,ready:'ticket'});
  f.requests.forEach(r=>respond(r,ready('ticket'))); await flush();
  assert.equal(uses(f).length,0); assert.equal(f.form.querySelector('[data-ticket-subject]').value,'Default title');
});

test('a source event during Use prevents the older response from inserting text', async t => {
  const f=page(t,{mode:'manual',ready:'reply'});
  f.requests.forEach(r=>respond(r,r.url.endsWith('/reply')?ready('reply'):empty)); await flush();
  f.panel('reply').querySelector('[data-ai-use]').click();
  f.window.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-source-changed'));
  respond(uses(f)[0],ready('reply')); await flush(); assert.equal(f.body.value,'');
});


test('Manual Prepare suggestions ensures the three eligible tasks independently', async t => {
  const f=page(t,{mode:'manual'}); f.requests.forEach(r=>respond(r,empty)); await flush();
  const button=f.window.document.querySelector('[data-ai-prepare-all]'); assert.equal(button.disabled,false);
  button.click(); assert.equal(posts(f).length,3,'Explicit Manual initial action must ensure the three eligible tasks');
  assert.ok(posts(f).every(r=>JSON.parse(r.options.body).action==='initial'));
});


test('unchanged polling preserves focused suggestion controls and does not reannounce Ready', async t => {
  const f=page(t,{mode:'manual',ready:'reply'});
  f.requests.forEach(r=>respond(r,r.url.endsWith('/reply')?ready('reply'):empty)); await flush();
  const panel=f.panel('reply'), button=panel.querySelector('[data-ai-use]'); button.focus();
  const message=panel.querySelector('[data-ai-message]').firstChild;
  f.timers.forEach(fn=>fn());
  assert.equal(panel.querySelector('[data-ai-use]'),button,'Unchanged polling must preserve the focused control node');
  assert.equal(f.window.document.activeElement,button);
  assert.equal(panel.querySelector('[data-ai-message]').firstChild,message,'Unchanged Ready must not be reannounced');
  f.visibility('hidden'); f.visibility('visible');
  f.requests.filter(r=>r.url.endsWith('/reply')).at(-1).resolve({ok:true,status:200,headers:{get:()=>null},json:async()=>ready('reply')});
  await flush(); assert.equal(panel.querySelector('[data-ai-use]'),button);
});

test('each replacement generation gets a fresh bounded polling allowance', async t => {
  const f=page(t,{mode:'manual'});
  let clock=1000000;
  f.window.Date.now=()=>clock;
  const pending={...empty,state:'preparing',generation,can_prepare:false};
  f.requests.forEach(r=>respond(r,r.url.endsWith('/reply') ? pending : empty));
  await flush();
  for (let index=0;index<210;index++) {
    clock+=2001;
    const before=f.requests.length;
    f.timers.forEach(fn=>fn());
    f.requests.slice(before).forEach(r=>respond(r,r.url.endsWith('/reply')
      ? (index===209 ? {...pending,state:'unavailable',can_prepare:true} : pending) : empty));
    await flush();
  }
  f.panel('reply').querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  const retry=posts(f).at(-1);
  assert.ok(retry,'Explicit retry must be available after the terminal generation');
  const next={...pending,generation:'d0620693-f65c-4ae7-9820-c8b5cde95fe4'};
  respond(retry,next);await flush();
  clock+=2001;
  const before=f.requests.length;
  f.timers.forEach(fn=>fn());
  const reads=f.requests.slice(before).filter(r=>r.url==='/assist/reply');
  assert.equal(reads.length,1,'A replacement generation must poll even after the prior generation exhausted its allowance');
  respond(reads[0],{...ready('reply'),generation:next.generation});await flush();
  assert.equal(f.panel('reply').dataset.state,'ready');
  assert.equal(posts(f).length,1,'Only the deliberate retry may admit replacement work');
});

test('fresh Manual to Prepare status changes how the next visible open prepares', async t => {
  const f=page(t,{mode:'manual'});
  f.requests.forEach(r=>respond(r,{...empty,mode:'prepare'}));await flush();
  assert.equal(posts(f).length,0,'A mode status read must never prepare by itself');
  f.visibility('hidden');f.visibility('visible');
  assert.equal(posts(f).length,3,'The next visible open must use the freshly accepted Prepare mode');
});

test('fresh Prepare to Manual status preserves deliberate initial controls on later opens', async t => {
  const f=page(t);
  f.requests.forEach(r=>respond(r,{...empty,mode:'prepare'}));await flush();
  let before=f.requests.length;
  f.window.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-source-changed'));
  f.requests.slice(before).forEach(r=>respond(r,{...empty,mode:'manual'}));await flush();
  before=f.requests.length;
  f.visibility('hidden');f.visibility('visible');
  assert.equal(posts(f).length,3,'A freshly accepted Manual mode must never send an automatic open');
  f.requests.slice(before).forEach(r=>respond(r,{...empty,mode:'manual'}));await flush();
  const button=f.panel('reply').querySelector('[data-ai-prepare]');
  assert.equal(button.hidden,false,'Fresh Manual mode must preserve its initial action');
  f.panel('reply').querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(posts(f).length,4);
  assert.equal(JSON.parse(posts(f).at(-1).options.body).action,'initial');
});

test('a visible reopen during an older request preserves its preparation intent', async t => {
  const f=page(t);
  const original=f.requests.slice();
  f.visibility('hidden');
  f.window.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-source-changed'));
  f.visibility('visible');
  original.forEach(r=>respond(r,{...empty,mode:'prepare'}));await flush();
  assert.equal(posts(f).length,6,'Each reopened workspace task must ensure current input after its older in-flight request settles');
});

test('a queued visible reopen never prepares after the document becomes hidden', async t => {
  const f=page(t);const original=f.requests.slice();
  f.visibility('hidden');f.visibility('visible');f.visibility('hidden');
  original.forEach(r=>respond(r,{...empty,mode:'prepare'}));await flush();
  assert.equal(posts(f).length,3,'Queued open intent must not prepare a hidden document');
});

test('fresh Manual mode cancels an older queued automatic open intent', async t => {
  const f=page(t);const original=f.requests.slice();
  f.visibility('hidden');f.visibility('visible');
  original.forEach(r=>respond(r,{...empty,mode:'manual'}));await flush();
  assert.equal(posts(f).length,3,'Fresh Manual mode must cancel queued automatic preparation');
  assert.equal(f.panel('reply').dataset.mode,'manual');
});

test('in-flight status reads synchronously disable explicit preparation controls', async t => {
  const f=page(t,{mode:'manual'}), panel=f.panel('reply');
  const button=panel.querySelector('[data-ai-prepare]');
  assert.equal(button.disabled,true,'An in-flight read must synchronously disable Prepare instead of silently losing its action');
  panel.querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(posts(f).length,0);
  f.requests.slice().forEach(r=>respond(r,empty));await flush();
  assert.equal(button.disabled,false);
  panel.querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(posts(f).length,1);
  assert.equal(button.disabled,true,'An admitted explicit request must synchronously disable its control too');
});

test('grouped Prepare waits for every eligible status read then ensures every task', async t => {
  const f=page(t,{mode:'manual'}), group=f.window.document.querySelector('[data-ai-prepare-all]');
  const reads=f.requests.slice();respond(reads[0],empty);respond(reads[1],empty);await flush();
  assert.equal(group.disabled,true,'Grouped Prepare must wait for every eligible task instead of dropping the still-reading task');
  group.dispatchEvent(new f.window.MouseEvent('click',{bubbles:true}));assert.equal(posts(f).length,0);
  respond(reads[2],empty);await flush();assert.equal(group.disabled,false);
  group.dispatchEvent(new f.window.MouseEvent('click',{bubbles:true}));
  assert.equal(posts(f).length,3);assert.equal(group.disabled,true);
});

test('a fresh Ready task disables Refresh synchronously during its status read', async t => {
  const f=page(t,{mode:'manual',ready:'reply'}), panel=f.panel('reply');
  const button=panel.querySelector('[data-ai-prepare]');
  assert.equal(button.disabled,true,'An in-flight Ready read must visibly disable Refresh');
  f.requests.slice().forEach(r=>respond(r,r.url.endsWith('/reply')?ready('reply'):empty));await flush();
  assert.equal(button.disabled,false);
  panel.querySelector('[data-ai-form]').dispatchEvent(new f.window.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(JSON.parse(posts(f).at(-1).options.body).action,'refresh');
  assert.equal(button.disabled,true);
});


function replaceLegacyReply(f, mode) {
  const template=f.window.document.createElement('template');
  template.innerHTML=f.markup('reply');
  const replacement=template.content.firstElementChild;replacement.dataset.mode=mode;
  f.window.document.querySelector('[data-copilot-reply-draft]').replaceWith(replacement);
  return replacement;
}

test('legacy polling replacements initialize Prepare without a tab or visibility event', async t => {
  const f=page(t,{mode:'manual',legacyReply:true});
  f.requests.forEach(r=>respond(r,empty));await flush();
  const replacement=replaceLegacyReply(f,'prepare');
  f.timers.forEach(fn=>fn());
  const opens=posts(f);
  assert.equal(opens.length,1,'A panel introduced by legacy polling must initialize and ensure its visible Prepare workspace');
  assert.equal(opens[0].url,'/assist/reply');
  respond(opens[0],ready('reply'));await flush();
  const use=replacement.querySelector('[data-ai-use]');
  assert.ok(use && !use.disabled,'A freshly initialized replacement must make current Ready output usable');
  f.timers.forEach(fn=>fn());
  assert.equal(posts(f).length,1,'Discovering an initialized replacement again must not create another open');
});

test('legacy polling replacements initialize Manual Ready insertion without generating', async t => {
  const f=page(t,{mode:'manual',ready:'reply',legacyReply:true});
  f.requests.forEach(r=>respond(r,empty));await flush();
  const replacement=replaceLegacyReply(f,'manual');
  f.timers.forEach(fn=>fn());
  const use=replacement.querySelector('[data-ai-use]');
  assert.ok(use && !use.disabled,'A Manual Ready panel introduced by legacy polling must initialize its insertion control');
  assert.equal(posts(f).length,0,'Discovering a Manual replacement must remain read-only');
  use.dispatchEvent(new f.window.MouseEvent('click',{bubbles:true}));
  const fresh=uses(f).at(-1);assert.ok(fresh,'Replacement insertion must still perform a fresh authorized Use read');
  respond(fresh,ready('reply'));await flush();
  assert.equal(f.body.value,'Fresh approved reply');
});

for (const deferred of ['hidden','prerender']) {
  test(`legacy Prepare replacements remain inert while ${deferred}`, t => {
    const f=page(t,{mode:'manual',legacyReply:true,[deferred]:true});
    replaceLegacyReply(f,'prepare');f.timers.forEach(fn=>fn());
    assert.equal(f.requests.length,0,'Discovering a replacement must not process a hidden or prerendered workspace');
    if(deferred==='hidden')f.visibility('visible');else f.activatePrerender();
    assert.equal(posts(f).length,1,'Activation should prepare only the newly enabled Prepare task');
  });
}
