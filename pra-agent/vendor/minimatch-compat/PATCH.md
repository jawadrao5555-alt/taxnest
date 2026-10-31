# Minimatch packaging compatibility

GHSA-g3hv-75wg-m249 affects upstream versions below 11.0.1. This package has its own TaxNest identity and delegates matching to the exact, unmodified registry package `minimatch@11.0.1` (installed through the minimatch-upstream alias). No vulnerable older matching engine is retained.

Legacy Electron packaging consumers call `require('minimatch')` as a function; current consumers use named exports. The facade supports both shapes and preserves the upstream constructors, helpers and globstar symbol. The facade preserves callable CommonJS exports/defaults and adds the legacy _glob metadata and String.match protocol to upstream nonrecursive Matcher segments for glob 7. It does not implement pattern parsing, compile regular expressions, or suppress upstream input validation.

Source inventory and every facade file hash are pinned in the npm portability guard. Lockfile package counts, registry sources, integrity values and graph hashes remain enforced. Tests must exercise actual ASAR packing/unpacking, dir-compare filters, filelist and glob consumers as well as the named/callable API.
