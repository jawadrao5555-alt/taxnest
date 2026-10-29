#!/usr/bin/env python3
"""Build SQL literals from the validated committed scope; no DB/transport access."""
import importlib
import json
import re
import sys


def scope_sql(spec):
    importlib.import_module('elaan-spec-parse').validate_scope(spec['audience'], spec['categories'])
    # Hex literals avoid shell/MySQL quoting modes and Unicode escaping ambiguity.
    literal = lambda value: "CONVERT(0x" + value.encode('utf-8').hex() + " USING utf8mb4)"
    scope = "is_published=1 AND audience=" + literal(spec['audience'])
    cats = spec['categories']
    if cats:
        scope += " AND JSON_VALID(target_categories) AND JSON_LENGTH(target_categories)=" + str(len(cats))
        scope += " AND JSON_CONTAINS(target_categories," + literal(json.dumps(cats)) + ")"
    else:
        scope += " AND (target_categories IS NULL OR target_categories='' OR target_categories='[]')"
    return scope


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3 or not re.fullmatch('[0-9a-fA-F]{40}', sys.argv[2]):
            raise ValueError('committed spec and exact SHA required')
        with open(sys.argv[1], encoding='utf-8') as handle:
            spec = importlib.import_module('elaan-spec-parse').parse_restricted_yaml(handle.read())
        print(scope_sql(spec))
    except (ValueError, OSError) as exc:
        print('Invalid announcement scope: ' + str(exc), file=sys.stderr)
        sys.exit(1)
