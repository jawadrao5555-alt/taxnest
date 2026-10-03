'use strict';

// TaxNest local security backport for GHSA-vfj7-8cjw-p6xm.
// Bound recursive walkers, including caller-supplied ASTs, before entering them.
const MAX_DEPTH = 100;
const assertDepth = ast => {
  const pending = [{ node: ast, depth: 0, exit: false }];
  const active = new Set();
  let count = 0;
  while (pending.length) {
    const { node, depth, exit } = pending.pop();
    if (!node || typeof node !== 'object') continue;
    if (exit) { active.delete(node); continue; }
    if (depth > MAX_DEPTH || active.has(node) || ++count > 20000) {
      throw new SyntaxError('Brace AST exceeds bounded depth or contains a cycle');
    }
    active.add(node);
    pending.push({ node, depth, exit: true });
    if (Array.isArray(node.nodes)) {
      for (let i = node.nodes.length - 1; i >= 0; i--) {
        pending.push({ node: node.nodes[i], depth: depth + 1, exit: false });
      }
    }
  }
};
module.exports = { MAX_DEPTH, assertDepth };
