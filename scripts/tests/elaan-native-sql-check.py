#!/usr/bin/env python3
"""Real MySQL 8 / MariaDB scope regression, using initialization SQL: no socket or service.

Only a newly created temporary datadir is touched. No application environment,
production credentials, host MySQL configuration or existing database is read.
"""
import importlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/lib'))
scope_sql = importlib.import_module('elaan-scope-sql').scope_sql


def run_sql(server, statements, directory, mariadb=False):
    sql = directory / 'fixture.sql'
    sql.write_text('\n'.join(statements) + '\n')
    data = directory / 'data'
    if mariadb:
        data.mkdir()
        args = [server, '--no-defaults', '--bootstrap', '--skip-grant-tables',
                '--datadir=' + str(data), '--character-set-server=utf8mb4',
                '--collation-server=utf8mb4_general_ci']
    else:
        args = [server, '--no-defaults', '--initialize-insecure',
                '--datadir=' + str(data), '--init-file=' + str(sql),
                '--character-set-server=utf8mb4', '--collation-server=utf8mb4_0900_ai_ci']
    if os.geteuid() == 0:
        args.append('--user=root')
    return subprocess.run(args, input=sql.read_text() if mariadb else None, capture_output=True, text=True, timeout=90)


def main():
    if os.environ.get('RC_SAFE_RUN') != '1':
        raise RuntimeError('Run through scripts/rc-safe-run')
    if sys.argv[1:] not in [[], ['--mariadb']]:
        raise RuntimeError('Only optional --mariadb is supported')
    mariadb = sys.argv[1:] == ['--mariadb']
    engine = 'MariaDB' if mariadb else 'MySQL 8'
    server = shutil.which(os.environ.get('MYSQLD', 'mariadbd' if mariadb else 'mysqld'))
    if not server:
        raise RuntimeError('Required '+engine+' server executable missing')
    version = subprocess.check_output([server, '--no-defaults', '--version'], text=True)
    if mariadb:
        if 'MariaDB' not in version or not any(v in version for v in ['10.6.', '10.11.']):
            raise RuntimeError('This regression requires MariaDB 10.6 or 10.11')
    elif 'Ver 8.0.' not in version or 'MariaDB' in version:
        raise RuntimeError('This regression requires real MySQL 8.0')
    header = ['CREATE DATABASE fictional CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
              'USE fictional;', 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;']
    with tempfile.TemporaryDirectory(prefix='taxnest-elaan-native-sql-') as temp:
        root = Path(temp)
        old = scope_sql({'audience': 'pos', 'categories': []}).replace(' COLLATE utf8mb4_unicode_ci', '')
        baseline = root / 'baseline'
        baseline.mkdir()
        result = run_sql(server, header + [
            'CREATE TABLE app_updates (audience VARCHAR(32),is_published BOOLEAN,target_categories JSON);',
            "INSERT INTO app_updates VALUES ('pos',1,NULL);",
            'SELECT COUNT(*) FROM app_updates WHERE ' + old + ';'], baseline, mariadb)
        if result.returncode == 0 or '1267' not in result.stderr:
            raise RuntimeError('Original '+engine+' collation failure was not reproduced: ' + result.stderr)
        statements = header + ['CREATE TABLE assertions (id INT PRIMARY KEY);']
        assertion = 0
        case = 0
        collations = ['utf8mb4_unicode_ci', 'utf8mb4_general_ci']
        if not mariadb:
            collations.append('utf8mb4_0900_ai_ci')
        for collation in collations:
            for kind in ['JSON', 'TEXT']:
                statements.append('CREATE TABLE app_updates (id INT PRIMARY KEY,audience VARCHAR(32),is_published BOOLEAN,target_categories ' + kind + ',deployment_key VARCHAR(64),archived_at DATETIME NULL,created_at DATETIME) CHARACTER SET utf8mb4 COLLATE ' + collation + ';')
                for audience, category in [('pos', 'restaurant'), ('fbr_pos', 'pharmacy'), ('di', 'di_services'), ('all', 'salon')]:
                    for categories in [[], [category]]:
                        case += 1
                        sha = f'{case:040x}'
                        scope = scope_sql({'audience': audience, 'categories': categories})
                        stored = "'" + json.dumps(categories) + "'" if categories else 'NULL'
                        statements.append(f"INSERT INTO app_updates VALUES (1,'{audience}',1,{stored},'{sha}',NULL,'2026-10-31 12:00:00');")
                        def expect(count, since=None):
                            nonlocal assertion
                            assertion += 1
                            query = f"SELECT COUNT(*) FROM app_updates WHERE {scope} AND deployment_key='{sha}' AND archived_at IS NULL"
                            if since:
                                query += f" AND created_at>'{since}'"
                            # A false assertion tries to insert a duplicate primary key.
                            statements.extend([f'INSERT INTO assertions VALUES ({assertion});', f'INSERT INTO assertions SELECT {assertion} WHERE ({query})<>{count};'])
                        expect(1)
                        expect(1, '2026-10-30 12:00:00')
                        expect(0, '2026-10-31 12:00:00')
                        for field, bad, original in [
                            ('audience', "'wrong'", f"'{audience}'"),
                            ('deployment_key', "'wrong'", f"'{sha}'"),
                            ('is_published', '0', '1'),
                            ('archived_at', "'2026-10-31 13:00:00'", 'NULL'),
                            ('target_categories', "'[]'" if categories else "'[\"restaurant\"]'", stored),
                        ]:
                            statements.append(f'UPDATE app_updates SET {field}={bad} WHERE id=1;')
                            expect(0)
                            statements.append(f'UPDATE app_updates SET {field}={original} WHERE id=1;')
                            expect(1)
                        if not categories:
                            for empty in ["'[]'"] + (["''"] if kind == 'TEXT' else []):
                                statements.append(f'UPDATE app_updates SET target_categories={empty} WHERE id=1;')
                                expect(1)
                        statements.append('DELETE FROM app_updates WHERE id=1;')
                        expect(0)
                statements.append('DROP TABLE app_updates;')
        fixed = root / 'fixed'
        fixed.mkdir()
        result = run_sql(server, statements, fixed, mariadb)
        if result.returncode:
            raise RuntimeError('Fixed '+engine+' scope regression failed: ' + result.stderr)
        print(f'PASS: original {engine} 1267 reproduced; {case} fixed audience/category/collation/schema cases, {assertion} native SQL assertions; no listener or existing DB used')


if __name__ == '__main__':
    main()
