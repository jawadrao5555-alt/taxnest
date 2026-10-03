'use strict';
const assert = require('node:assert/strict');
const test = require('node:test');
const braces = require('../../tools/npm-patches/braces');

test('deep patterns fail with a bounded syntax error before recursive walkers', () => {
  for (const token of ['{', '(']) {
    const close = token === '{' ? '}' : ')';
    const pattern = token.repeat(4500) + 'x' + close.repeat(4500);
    for (const method of ['parse', 'compile', 'expand', 'stringify']) {
      assert.throws(() => braces[method](pattern), err =>
        err instanceof SyntaxError && /bounded depth/.test(err.message));
    }
  }
});

test('AST callers cannot bypass depth limits, including invalid and cyclic nodes', () => {
  let ast = { type: 'text', value: 'x' };
  for (let i = 0; i < 4500; i++) ast = { type: 'root', nodes: [ast] };
  const cycle = { type: 'root', nodes: [] };
  cycle.nodes.push(cycle);
  for (const method of ['compile', 'expand', 'stringify']) {
    assert.throws(() => braces[method](ast), SyntaxError);
    assert.throws(() => braces[method](cycle), SyntaxError);
  }
});

test('ordinary compile, expansion, ranges, escaped braces and stringify stay compatible', () => {
  assert.equal(braces.compile('src/{js,css}/app.{js,css}'), 'src/(js|css)/app.(js|css)');
  assert.deepEqual(braces.expand('x/{01..03}'), ['x/01', 'x/02', 'x/03']);
  assert.deepEqual(braces.expand('{a,b}/{c,d}'), ['a/c', 'a/d', 'b/c', 'b/d']);
  assert.deepEqual(braces.expand('x/\\{a,b\\}'), ['x/{a,b}']);
  assert.equal(braces.stringify(braces.parse('a/{b,c}/d')), 'a/{b,c}/d');
  assert.deepEqual(braces(['{a,b}', '{b,c}'], { expand: true, nodupes: true }), ['a', 'b', 'c']);
});
