# TaxNest bounded-depth backport

This is a local MIT-licensed fork of npm `braces@3.0.3`, not an upstream
release. The upstream tarball SHA-1 is
`490332f40919452272d55a8480adc0c441358789`.
It is explicitly named `@taxnest/braces-bounded@3.0.3-taxnest.1` so it cannot
be mistaken for unmodified upstream code.

Source advisory: https://github.com/advisories/GHSA-vfj7-8cjw-p6xm
Reproduction and recommended depth bound:
https://github.com/micromatch/braces/issues/70

Changes from upstream:

- Parse limits the combined brace/parenthesis stack to 100 before recursive
  cleanup can run. The existing character limit remains unchanged.
- Compile, expand and stringify preflight every AST using an iterative depth
  check. Caller-supplied deep/cyclic ASTs cannot bypass the parse bound.
- The preflight limits traversal to 20,000 nodes and depth 100, keeping the
  existing recursive walkers below that bound. Ordinary brace/range syntax
  and dependency requirements are unchanged.
- The upstream license, authors and source are retained.

The lock portability guard pins every source file hash. CI exercises deep
patterns, caller ASTs, cycles and normal expansion, in addition to the real
Tailwind/Vite and browser lanes. npm audit does not independently certify
this local fork: source-integrity checks and behavioral tests are mandatory.
Remove the fork only after an upstream patched release passes these tests.
