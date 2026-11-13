const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {createRequire}=require('node:module');
const test=require('node:test');
const root=process.env.ASSIST_TEST_REPO_ROOT||path.resolve(__dirname,'../../../..');
const {JSDOM}=createRequire(path.join(root,'packages/widget-js/package.json'))('jsdom');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(t){
 const copy={tasks:{summary:'Catch up',reply:'Suggested reply',knowledge:'Sources'},states:{ready:'Ready',preparing:'Preparing',stale:'Stale',unavailable:'Unavailable',disabled:'Disabled'},position:'Used :count text messages; through message :position.',no_position:'No position yet.'};
 const attr=JSON.stringify(copy).replaceAll('"','&quot;');
 const dom=new JSDOM(`<div data-tab-panel="conversation"><div data-reply-shell><textarea data-reply-body>Human draft.</textarea><aside data-assist-copy="${attr}"><p role="status" aria-live="polite" data-assist-live></p><details data-assist-rail open>
 <section data-assist-section="summary" data-assist-resting-state="unavailable"><div data-assist-resting hidden>Unavailable</div><div data-ai-assist data-state="ready" data-generation="summary-1" data-source-ordinal="3" data-source-message-count="2" data-source-url="/conversation#conversation-message-72"></div></section>
 <section data-assist-section="reply" data-assist-resting-state="unavailable"><div data-assist-resting hidden>Unavailable</div><div data-ai-assist data-state="preparing" data-generation="reply-1"></div></section>
 <section data-assist-section="knowledge" data-assist-resting-state="disabled"><div data-assist-resting hidden>Disabled</div><div data-copilot-knowledge-suggestion data-state="ready" data-generation="sources-1" data-source-ordinal="2" data-source-message-count="2" data-source-url="/conversation#conversation-message-71"><p data-copilot-knowledge-suggestion-stale>Stale</p></div></section>
 ${['summary','reply','knowledge'].map(task=>`<p><span data-assist-freshness-task="${task}"></span><a data-assist-position-task="${task}" hidden></a></p>`).join('')}
 </details></aside></div></div>`,{url:'https://support.example.test/conversation',runScripts:'outside-only',pretendToBeVisual:true});
 t.after(()=>dom.window.close());const {window}=dom;let requests=0;
 window.matchMedia=()=>({matches:true});window.fetch=()=>{requests++;throw new Error('No request allowed');};
 const source=fs.readFileSync(process.env.ASSIST_RAIL_SCRIPT_COPY||path.join(root,'apps/server/resources/views/agent/conversations/partials/assist-rail-script.blade.php'),'utf8').replace(/<\/?script>/g,'');window.eval(source);
 const q=selector=>window.document.querySelector(selector);return{window,q,requests:()=>requests};
}
test('mixed task freshness is separate and initial rendering is silent',async t=>{
 const f=fixture(t);await flush();
 assert.equal(f.q('[data-assist-freshness-task="summary"]').textContent,'Ready');
 assert.equal(f.q('[data-assist-position-task="summary"]').textContent,'Used 2 text messages; through message 3.');
 assert.equal(f.q('[data-assist-freshness-task="reply"]').textContent,'Preparing No position yet.');
 assert.equal(f.q('[data-assist-freshness-task="knowledge"]').textContent,'Stale');
 assert.equal(f.q('[data-assist-live]').textContent,'','Initial page content must not produce redundant live announcements');
 assert.equal(f.q('[data-assist-position-task="summary"]').getAttribute('href'),'#conversation-message-72','Included-position navigation must stay in the current conversation and preserve its draft/query');
 assert.equal(f.requests(),0);
});
test('meaningful prepared changes announce once without moving focus or preparing work',async t=>{
 const f=fixture(t),body=f.q('[data-reply-body]'),card=f.q('[data-assist-section="reply"] [data-ai-assist]');body.focus();
 card.dataset.state='ready';card.dataset.sourceOrdinal='5';card.dataset.sourceMessageCount='4';card.dataset.sourceUrl='/conversation#conversation-message-90';await flush();
 const announcement=f.q('[data-assist-live]').textContent;assert.match(announcement,/Suggested reply: Ready Used 4 text messages; through message 5/);
 card.dispatchEvent(new f.window.CustomEvent('wayfindr:assist-task-updated',{bubbles:true,detail:{task:'reply'}}));await flush();assert.equal(f.q('[data-assist-live]').textContent,announcement);
 assert.equal(f.window.document.activeElement,body);assert.equal(body.value,'Human draft.');assert.equal(f.requests(),0);
});
test('native replacement and withdrawal retain correct footer state and drop obsolete links',async t=>{
 const f=fixture(t),section=f.q('[data-assist-section="knowledge"]'),card=section.querySelector('[data-copilot-knowledge-suggestion]');
 card.replaceWith(card.cloneNode(true));await flush();assert.equal(f.q('[data-assist-freshness-task="knowledge"]').textContent,'Stale');
 section.querySelector('[data-copilot-knowledge-suggestion]').remove();await flush();
 assert.equal(f.q('[data-assist-freshness-task="knowledge"]').textContent,'Disabled No position yet.');
 assert.equal(f.q('[data-assist-position-task="knowledge"]').hidden,true);assert.equal(f.q('[data-assist-position-task="knowledge"]').hasAttribute('href'),false);assert.equal(f.requests(),0);
});
test('untrusted or missing provenance never creates a navigation link',async t=>{
 const f=fixture(t),card=f.q('[data-assist-section="summary"] [data-ai-assist]');
 card.dataset.sourceUrl='https://foreign.example.test/#conversation-message-72';await flush();assert.equal(f.q('[data-assist-position-task="summary"]').hidden,true);
 card.dataset.sourceOrdinal='';card.dataset.sourceUrl='/conversation#conversation-message-72';await flush();assert.equal(f.q('[data-assist-position-task="summary"]').hasAttribute('href'),false);assert.equal(f.requests(),0);
});
