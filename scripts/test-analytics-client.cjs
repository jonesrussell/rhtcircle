// Runs the real client in a small deterministic DOM fixture, without a browser.
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = readFileSync(__dirname + '/../public/js/rht-analytics.js', 'utf8');
const registry = {post: {path:'/elections/test',source:'facebook',medium:'social',campaign:'election',content:'final-week'}};
const tagged = '?utm_source=facebook&utm_medium=social&utm_campaign=election&utm_content=final-week';
function run({search='',path='/elections/test',storage=new Map(),blocked=false,dnt=false,gpc=false}={}) {
  const beacons=[];
  const navigator={doNotTrack:dnt?'1':'0',globalPrivacyControl:gpc};
  const document={
    currentScript:{getAttribute:()=>JSON.stringify(registry)},referrer:'',readyState:'loading',
    documentElement:{scrollTop:0,scrollHeight:2000,offsetHeight:2000,clientHeight:700},body:{scrollHeight:2000},
    addEventListener:()=>{},
  };
  const window={innerHeight:700,pageYOffset:0,addEventListener:()=>{}};
  const sessionStorage={
    getItem:k=>{if(blocked)throw Error();return storage.get(k)||null;},
    setItem:(k,v)=>{if(blocked)throw Error();storage.set(k,v);},
    removeItem:k=>{if(blocked)throw Error();storage.delete(k);},
  };
  vm.runInNewContext(code,{window,navigator,document,sessionStorage,location:{search,pathname:path},URLSearchParams,
    crypto:{randomUUID:()=> '41a88644-90be-44f0-b64b-9711cad40176'},fetch:(u,o)=>{beacons.push(JSON.parse(o.body));return Promise.resolve({});},
    requestAnimationFrame:f=>f(),Date,Math,Blob,setTimeout});
  return {context:()=>JSON.parse(JSON.stringify(window.rhtAnalytics.contactContext())),beacons,storage,navigator};
}
const first=run({search:tagged});
assert.deepEqual(first.context(),{analytics_enabled:true,campaign:'post'});
assert.equal(first.beacons[0].campaign,'post');
assert.equal(run({path:'/contact',storage:first.storage}).context().campaign,'post');
assert.equal(run({search:'?utm_campaign=someone@example.com',storage:first.storage}).context().campaign,'');
assert.equal(run({search:tagged+'&utm_source=facebook'}).context().campaign,'');
assert.equal(run({search:tagged,path:'/contact'}).context().campaign,'');
const expired=new Map([['rht-campaign',JSON.stringify({key:'post',expires:Date.now()-1})]]);
assert.equal(run({storage:expired}).context().campaign,'');
for(const choice of [{dnt:true},{gpc:true}]) {
  const r=run({search:tagged,...choice});
  assert.equal(r.beacons.length,0);
  assert.deepEqual(r.context(),{analytics_enabled:false,campaign:''});
}
const change=run({search:tagged});
change.navigator.globalPrivacyControl=true;
assert.equal(change.context().analytics_enabled,false);
assert.equal(change.storage.size,0);
const noStorage=run({search:tagged,blocked:true});
assert.equal(noStorage.context().campaign,'post');
assert.equal(run({path:'/contact',blocked:true}).context().campaign,'');
console.log('Campaign client checks passed: tagged landing, same-tab carry, expiry, invalid tags, privacy controls and unavailable storage.');
