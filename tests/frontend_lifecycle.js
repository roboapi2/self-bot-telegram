// Test the actual Persian status helpers without a browser or private accounts.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/app.js'), 'utf8');
const helpers = source.slice(source.indexOf('function lifecycleLabel('), source.indexOf('function accountCard('));
const ctx = vm.createContext({Date});
vm.runInContext(helpers, ctx);
for (const state of ['stopped', 'queued', 'starting', 'running', 'stopping', 'failed', 'retrying']) {
  const label = ctx.lifecycleLabel({state, running:state === 'running'});
  assert(label && !label.includes('undefined'));
  assert(state === 'running' || !label.includes('فعال'));
}
assert(ctx.lifecycleFailure({state:'failed',failure:{category:'RAW_PRIVATE_MESSAGE'}}).includes('خطای حساب'));
assert(!ctx.lifecycleFailure({state:'failed',failure:{category:'RAW_PRIVATE_MESSAGE'}}).includes('RAW_PRIVATE_MESSAGE'));
assert(ctx.lifecycleFailure({state:'failed',failure:{category:'lock_contention'}}).includes('پردازش دیگری'));
assert.strictEqual(ctx.lifecycleFailure({state:'running',failure:{category:'startup_exception'}}), '');
assert(ctx.lifecycleFailure({state:'failed',failure:{category:'log_unwritable'}}).includes('مسیر لاگ'));
assert(ctx.lifecycleFailure({state:'failed',failure:{category:'worker_bootstrap_failure'}}).includes('PID'));
console.log('13 Persian lifecycle status/privacy checks passed');
