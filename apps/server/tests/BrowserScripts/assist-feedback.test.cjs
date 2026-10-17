const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {createRequire}=require('node:module');
const test=require('node:test');
const root=path.resolve(__dirname,'../../../..');
const {JSDOM}=createRequire(path.join(root,'packages/widget-js/package.json'))('jsdom');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const generation='11111111-1111-4111-8111-111111111111';
async function fixture(t,{hidden=false,closed=false,deny=false,viewFailures=[],belowFold=false,task='reply',hasArticle=false}={}) {
 const markup=`<main data-tab-panel ${hidden?'hidden':''}><div data-reply-shell><details data-assist-rail ${closed?'':'open'}><summary>Assist</summary><section data-ai-assist data-task="${task}" data-state="ready" data-generation="${generation}" data-initial="{}"><div data-ai-output>Human-reviewed synthetic suggestion${hasArticle?'<button data-ai-use>Insert excerpt</button>':''}</div><div data-assist-feedback data-feedback-url="/feedback" data-feedback-copy='{"choose":"Choose first","saved":"Saved","unavailable":"Unavailable"}'><div data-feedback-controls hidden><button type="button" data-feedback-reject>Reject</button><select data-feedback-usefulness><option value=""></option><option value="useful">Useful</option><option value="not_useful">Not useful</option><option value="unsure">Unsure</option></select><label data-feedback-correction-label hidden><select data-feedback-correction><option value="unassessed">Unknown</option><option value="none">None</option><option value="minor">Minor</option><option value="material">Material</option></select></label><button type="button" data-feedback-save>Save</button></div><p data-feedback-status></p></div></section></details></div></main>`;
 const dom=new JSDOM(markup,{url:'https://support.example.test/conversation',runScripts:'outside-only',pretendToBeVisual:true});t.after(()=>dom.window.close());
 const {window}=dom;const requests=[],timers=[];let revision=0,used=false,rejected=false;
 window.Element.prototype.getBoundingClientRect=function(){return {top:belowFold?10000:10,bottom:belowFold?10020:30,left:0,right:200,width:200,height:20};};
 window.Element.prototype.getClientRects=function(){return this.closest('[hidden]')?[]:[{}];};
 window.setInterval=fn=>timers.push(fn);
 window.fetch=async(url,options)=>{const input=JSON.parse(options.body);requests.push(input);const failure=input.event==='viewed'?viewFailures.shift():null;if(failure==='network')throw new TypeError('Network error');if(failure==='server')return {ok:false};if(failure==='json')return {ok:true,json:async()=>{throw new SyntaxError('Interrupted response');}};if(input.event==='assessment')revision++;if(input.event==='used')used=true;if(input.event==='rejected')rejected=true;return {ok:!deny,json:async()=>({generation,viewed:true,used,rejected,revision,usefulness:input.usefulness||null,correction:input.correction||null})};};
 window.Storage.prototype.setItem=()=>{throw Error('Feedback must not persist text in browser storage');};
 await flush(); // Settle JSDOM's initial details toggle before measuring request retries.
 window.eval(fs.readFileSync(path.join(root,'apps/server/resources/views/agent/conversations/partials/assist-feedback-script.blade.php'),'utf8').replace(/<\/?script>/g,''));await flush();
 return {onScreen:()=>{belowFold=false;},offScreen:()=>{belowFold=true;},window,requests,timers,panel:window.document.querySelector('[data-ai-assist]'),main:window.document.querySelector('main'),rail:window.document.querySelector('details')};
}
test('visible rendered output records one view across polling and no use from readiness',async t=>{
 const f=await fixture(t);for(let i=0;i<5;i++){f.timers[0]();await flush();}
 assert.deepEqual(f.requests,[{event:'viewed',generation}]);
});
for(const options of [{hidden:true},{closed:true}])test('hidden workspaces and collapsed rails do not report views',async t=>{
 const f=await fixture(t,options);assert.equal(f.requests.length,0);f.main.hidden=false;f.rail.open=true;f.timers[0]();await flush();assert.equal(f.requests.length,1);assert.equal(f.requests[0].event,'viewed');
});
test('only explicit rejection and assessment count; insertion receipt stays metadata-only',async t=>{
 const f=await fixture(t);f.window.wayfindrAssistFeedback.used(f.panel,generation,'opaque-receipt');await flush();
 const root=f.panel.querySelector('[data-assist-feedback]');assert.equal(root.querySelector('[data-feedback-correction-label]').hidden,false);
 root.querySelector('[data-feedback-save]').click();await flush();assert.equal(f.requests.length,2);
 root.querySelector('[data-feedback-usefulness]').value='useful';root.querySelector('[data-feedback-correction]').value='material';root.querySelector('[data-feedback-save]').click();await flush();
 root.querySelector('[data-feedback-usefulness]').value='not_useful';root.querySelector('[data-feedback-save]').click();await flush();
 root.querySelector('[data-feedback-reject]').click();await flush();
 assert.deepEqual(f.requests.map(x=>x.event),['viewed','used','assessment','assessment','rejected']);
 assert.deepEqual(f.requests[1],{event:'used',receipt:'opaque-receipt',generation});
 assert.equal(f.requests[2].expected_revision,0);assert.equal(f.requests[3].expected_revision,1);
 assert.equal(JSON.stringify(f.requests).includes('Human-reviewed'),false);assert.equal(root.querySelector('[data-feedback-reject]').disabled,true);
});
test('failed feedback remains unknown and reports an accessible failure',async t=>{
 const f=await fixture(t,{deny:true});assert.equal(f.panel.querySelector('[data-feedback-status]').textContent,'Unavailable');
 f.timers[0]();await flush();assert.equal(f.requests.length,2,'A later visibility scan may retry the idempotent view');
});

for(const failure of ['network','server','json'])test(`a ${failure} view failure retries once visible and permits assessment`,async t=>{
 const f=await fixture(t,{viewFailures:[failure]});assert.equal(f.requests.length,1);
 f.offScreen();f.timers[0]();await flush();assert.equal(f.requests.length,1,'A failed request does not invent a view while output is off screen');
 f.onScreen();f.timers[0]();f.timers[0]();await flush();assert.equal(f.requests.length,2,'The displayed generation retries without concurrent duplicate views');
 assert.equal(f.panel.querySelector('[data-feedback-status]').textContent,'','Recovery clears the old failure status');
 for(let i=0;i<3;i++){f.timers[0]();await flush();}assert.equal(f.requests.length,2,'A successful view is not retried');
 const root=f.panel.querySelector('[data-assist-feedback]');root.querySelector('[data-feedback-usefulness]').value='useful';root.querySelector('[data-feedback-save]').click();await flush();
 assert.deepEqual(f.requests.map(x=>x.event),['viewed','viewed','assessment']);assert.equal(f.requests[2].expected_revision,0);assert.equal(root.querySelector('[data-feedback-status]').textContent,'Saved');
});

test('failed explicit feedback is not automatically resubmitted as another vote',async t=>{
 const f=await fixture(t);const root=f.panel.querySelector('[data-assist-feedback]');
 f.window.fetch=async(url,options)=>{f.requests.push(JSON.parse(options.body));return {ok:false};};
 root.querySelector('[data-feedback-usefulness]').value='useful';root.querySelector('[data-feedback-save]').click();await flush();
 for(let i=0;i<3;i++){f.timers[0]();await flush();}
 assert.deepEqual(f.requests.map(x=>x.event),['viewed','assessment']);assert.equal(root.querySelector('[data-feedback-status]').textContent,'Unavailable');
});

test('a failed old generation view cannot reset the new generation attempt',async t=>{
 const f=await fixture(t,{belowFold:true});let rejectOld;const next='22222222-2222-4222-8222-222222222222';
 f.window.fetch=async(url,options)=>{const input=JSON.parse(options.body);f.requests.push(input);if(input.generation===generation)return new Promise((resolve,reject)=>{rejectOld=reject;});return {ok:true,json:async()=>({viewed:true,used:false,rejected:false,revision:0})};};
 f.onScreen();f.timers[0]();await flush();f.panel.dataset.generation=next;f.timers[0]();rejectOld(new TypeError('Old request failed'));await flush();
 for(let i=0;i<3;i++){f.timers[0]();await flush();}
 assert.deepEqual(f.requests,[{event:'viewed',generation},{event:'viewed',generation:next}]);assert.equal(f.panel.querySelector('[data-feedback-status]').textContent,'');
});

test('suggestions below the viewport remain unviewed until they actually enter view',async t=>{
 const f=await fixture(t,{belowFold:true});assert.equal(f.requests.length,0,'Below-fold rendering is not a view');
 f.onScreen();f.window.dispatchEvent(new f.window.Event('scroll'));f.timers[0]();await flush();assert.equal(f.requests.length,1);
});

test('assessment controls remain usable after viewed output scrolls above the viewport',async t=>{
 const f=await fixture(t);f.offScreen();f.timers[0]();await flush();assert.equal(f.panel.querySelector('[data-feedback-controls]').hidden,false);assert.equal(f.requests.length,1);
});

test('empty article matches do not report a view while an actual article suggestion does',async t=>{
 const empty=await fixture(t,{task:'knowledge'});assert.equal(empty.requests.length,0);
 const article=await fixture(t,{task:'knowledge',hasArticle:true});assert.equal(article.requests.length,1);assert.equal(article.requests[0].event,'viewed');
});
