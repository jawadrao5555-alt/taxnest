#!/usr/bin/env python3
"""Negative tests for exact schema-only publication, no database connection."""
import copy
import importlib.util
import json
import pathlib
import sys
import unittest

root = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / 'scripts/lib'))
spec = importlib.util.spec_from_file_location('evidence', root / 'scripts/lib/rc-evidence-sanitizer.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class SchemaEvidenceTest(unittest.TestCase):
    def fixture(self):
        return {field: {'data_type': 'decimal', 'numeric_precision': 18, 'numeric_scale': 4,
                        'is_nullable': 'NO', 'column_default': '0.0000'} for field in m.SCHEMA_FIELDS}
    def test_only_safe_columns_and_scalars_survive(self):
        value = self.fixture()
        self.assertEqual(value, json.loads(m.validated_schema_evidence(json.dumps(value).encode())))
    def test_unknown_column_attributes_payload_and_secret_defaults_fail_closed(self):
        cases=[]
        v=self.fixture();v['users.password']=v.pop('inventory_stocks.quantity');cases.append(v)
        for field,content in [('rows', ['PRIVATE']), ('sql', 'SELECT *'), ('token','SECRET')]:
            v=self.fixture();v['inventory_stocks.quantity'][field]=content;cases.append(v)
        for key,value in [('column_default','PRIVATE-KEY'),('column_default','/private/path'),('numeric_precision',True),('numeric_scale',999),('is_nullable','UNKNOWN'),('data_type','text')]:
            v=self.fixture();v['inventory_stocks.quantity'][key]=value;cases.append(v)
        for v in cases:
            with self.assertRaises(ValueError):m.validated_schema_evidence(json.dumps(v).encode())
        for raw in [b'[]', b'not json', b' ' * 8193]:
            with self.assertRaises(ValueError):m.validated_schema_evidence(raw)

if __name__ == '__main__':unittest.main()
