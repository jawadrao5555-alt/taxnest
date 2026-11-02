'use strict';
const upstream = require('minimatch-upstream');
// Preserve old packaging APIs; pattern parsing and matching remain upstream.
function callable(api) {
  const match = (path, pattern, options) =>
    (typeof api === 'function' ? api : api.minimatch)(path, pattern, options);
  Object.assign(match, api);
  match.Minimatch = class extends api.Minimatch {
    constructor(pattern, options) {
      super(pattern, options);
      // glob 7 reads _glob and calls String.match on non-literal segments.
      // Upstream 11 uses nonrecursive Matcher objects instead of RegExp.
      for (const row of this.set) for (const part of row) {
        if (part && typeof part === 'object' && typeof part.test === 'function') {
          part._glob = part.pattern;
          part[Symbol.match] = function (text) { return this.test(text) ? [text] : null; };
        }
      }
    }
  };
  match.defaults = options => callable(api.defaults(options));
  return match;
}
module.exports = callable(upstream);
