#!/usr/bin/env python3
"""Removes what smoke_up.py made: the smoke user, its household (recipes, menus, lists cascade),
its media folder, and frontend/vite.smoke.config.ts. Stop the :5175 dev server yourself.

Run from the repo root:  python3 -I .claude/skills/ui-improvement/scripts/smoke_down.py
"""
import subprocess
from pathlib import Path

EMAIL = 'smoke@example.invalid'
HOUSEHOLD = 'Smoke test'
ROOT = Path(__file__).resolve().parents[4]


def psql(sql: str) -> str:
    result = subprocess.run(
        ['docker', 'exec', '-i', 'tabooki-database-1', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1'],
        input=sql, capture_output=True, text=True, check=True,
    )
    return result.stdout.strip()


def main() -> None:
    household = psql(f"select id from household where name = '{HOUSEHOLD}';")
    psql(f"begin; delete from \"user\" where email = '{EMAIL}'; delete from household where name = '{HOUSEHOLD}'; commit;")
    if household:
        subprocess.run(['docker', 'exec', 'tabooki-php-1', 'rm', '-rf', f'/app/var/media/{household}'], check=True)
    (ROOT / 'frontend' / 'vite.smoke.config.ts').unlink(missing_ok=True)
    print('Smoke household removed.' if household else 'No smoke household found; config removed if present.')


if __name__ == '__main__':
    main()
