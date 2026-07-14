---
name: Unicode heredoc writes truncate files
description: Writing file content containing emoji via bash heredoc/python with \ud escapes can silently truncate the target file
---

Writing Blade/JS content that contains emoji through a bash heredoc or a python
string using surrogate-pair escapes (`\ud83d\udda8`-style) can fail mid-write and
leave the target file TRUNCATED — the shell/python chokes on the surrogate pair,
the partial buffer is already flushed, and the view 500s.

**Why:** surrogate escapes are invalid as lone code points in Python 3 strings;
the failure happens after the file was opened for writing.

**How to apply:** never embed `\ud`-escapes in generated file content. Use the
real character or the full code point form (`\U0001F5A8`), write atomically
(write to temp file, then move), and if a file got truncated, restore via
`git show HEAD:path > path` and re-apply edits.
