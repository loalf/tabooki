#!/usr/bin/env python3
"""Throwaway household for UI screenshots: a user, six typed-in recipes, and menus for last week
and this week. Also writes frontend/vite.smoke.config.ts (dev server on :5175 proxying /api to
Caddy, because Chrome doesn't trust Caddy's local CA).

Run from the repo root:  python3 -I .claude/skills/ui-improvement/scripts/smoke_up.py
Undo with smoke_down.py. Saving the recipes queues the usual annotate/translate model calls
(a few cents); no links are saved, so no extraction runs.
"""
import datetime as dt
import http.cookiejar
import json
import ssl
import subprocess
import sys
import urllib.request
from pathlib import Path

EMAIL = 'smoke@example.invalid'
PASSWORD = 'smoke-pass-123'
HOUSEHOLD = 'Smoke test'
API = 'https://localhost/api'
ROOT = Path(__file__).resolve().parents[4]

RECIPES = [
    ('Lentejas estofadas con chorizo', 4, 15, 45, ['lunch'], ['cuchara', 'invierno'],
     ['300 g de lentejas pardinas', '1 chorizo', '1 cebolla', '2 zanahorias', '2 dientes de ajo', '1 cdta de pimentón', '1 l de caldo de verduras'],
     ['Pica la cebolla, la zanahoria y el ajo.', 'Sofríe la verdura 10 minutos en aceite de oliva.', 'Añade el chorizo en rodajas y el pimentón.', 'Echa las lentejas y el caldo y cuece 40 minutos a fuego lento.']),
    ('Ensalada de garbanzos, tomate y feta', 2, 15, 0, ['lunch', 'dinner'], ['rápida', 'verano'],
     ['400 g de garbanzos cocidos', '2 tomates', '100 g de queso feta', '1/2 cebolla morada', 'Aceite de oliva', 'Comino'],
     ['Escurre y lava los garbanzos.', 'Trocea el tomate y la cebolla.', 'Mezcla todo con el feta desmenuzado y aliña.']),
    ('Salmón al horno con patatas y eneldo', 4, 10, 30, ['dinner'], ['pescado', 'horno'],
     ['4 lomos de salmón', '600 g de patatas', '1 limón', 'Eneldo fresco', 'Sal y pimienta'],
     ['Precalienta el horno a 200 ºC.', 'Corta las patatas en rodajas finas y hornéalas 15 minutos.', 'Pon el salmón encima con limón y eneldo y hornea 15 minutos más.']),
    ('Tortilla de patatas jugosa', 4, 15, 25, ['dinner'], ['clásica'],
     ['6 huevos', '700 g de patatas', '1 cebolla', 'Aceite de oliva', 'Sal'],
     ['Pela y corta las patatas y la cebolla.', 'Confítalas en aceite a fuego medio 20 minutos.', 'Escúrrelas y mézclalas con los huevos batidos.', 'Cuaja la tortilla 3 minutos por cada lado.']),
    ('Curry de pollo con leche de coco', 4, 15, 30, ['lunch', 'dinner'], ['especiado'],
     ['600 g de muslos de pollo', '400 ml de leche de coco', '1 cebolla', '2 cdas de pasta de curry', '200 g de arroz basmati', 'Cilantro'],
     ['Dora el pollo troceado.', 'Añade la cebolla y la pasta de curry y rehoga 5 minutos.', 'Vierte la leche de coco y cuece 20 minutos.', 'Sirve con arroz y cilantro.']),
    ('Crema de calabaza y jengibre', 4, 10, 25, ['dinner'], ['cuchara', 'ligera'],
     ['1 kg de calabaza', '1 puerro', '1 trozo de jengibre', '750 ml de caldo de verduras'],
     ['Trocea la calabaza y el puerro.', 'Rehoga con el jengibre rallado.', 'Cubre con caldo y cuece 20 minutos.', 'Tritura hasta que quede fina.']),
]

VITE_CONFIG = """// Throwaway: created by .claude/skills/ui-improvement/scripts/smoke_up.py, removed by smoke_down.py.
// SMOKE_SLOW=<regex> holds matching requests back for SMOKE_SLOW_MS (default 60 s), to see loading states.
import { mergeConfig, type Plugin } from 'vite'
import base from './vite.config'
const slow: Plugin = {
  name: 'smoke-slow',
  configureServer(server) {
    const pattern = process.env.SMOKE_SLOW ? new RegExp(process.env.SMOKE_SLOW) : null
    const ms = Number(process.env.SMOKE_SLOW_MS ?? 60000)
    server.middlewares.use((req, _res, next) => (pattern?.test(req.url ?? '') ? setTimeout(next, ms) : next()))
  },
}
export default mergeConfig(base, {
  plugins: [slow],
  server: { port: 5175, hmr: false, proxy: { '/api': { target: { protocol: 'https:', host: 'localhost', port: 443, servername: 'localhost' }, secure: false, changeOrigin: true } } },
})
"""


def main() -> None:
    create = subprocess.run(
        ['docker', 'exec', 'tabooki-php-1', 'bin/console', 'app:user:create', EMAIL, '--name=Smoke', f'--household={HOUSEHOLD}', f'--password={PASSWORD}', '-n'],
        capture_output=True, text=True,
    )
    if create.returncode != 0:
        sys.exit(f'Could not create the smoke user (already there? run smoke_down.py first):\n{create.stdout}{create.stderr}')

    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def post(path: str, body: dict, content_type: str = 'application/ld+json') -> dict:
        request = urllib.request.Request(f'{API}{path}', data=json.dumps(body).encode(), headers={'Content-Type': content_type}, method='POST')
        with opener.open(request) as response:
            raw = response.read()
            return json.loads(raw) if raw else {}

    post('/login', {'email': EMAIL, 'password': PASSWORD}, 'application/json')

    for title, servings, prep, cook, meals, tags, ingredients, steps in RECIPES:
        post('/recipes', {
            'title': title, 'language': 'es', 'servings': servings, 'prepMinutes': prep, 'cookMinutes': cook,
            'mealTypes': meals, 'tags': tags, 'ingredients': [{'rawText': line} for line in ingredients], 'steps': steps,
            'notes': None, 'favorite': title.startswith('Tortilla'), 'planNext': False, 'needsReview': False,
        })

    monday = dt.date.today() - dt.timedelta(days=dt.date.today().weekday())
    for week in (monday - dt.timedelta(days=7), monday):
        slots = [
            {'date': (week + dt.timedelta(days=d)).isoformat(), 'slot': slot, 'servings': 3}
            for d in range(7) for slot in ('lunch', 'dinner')
            if not (d == 5 and slot == 'lunch')  # a meal eaten out, so an empty slot shows
        ]
        post('/meal-plans', {'weekStart': week.isoformat(), 'slots': slots, 'leftovers': True, 'weekdayMaxMinutes': None, 'tweak': None})

    (ROOT / 'frontend' / 'vite.smoke.config.ts').write_text(VITE_CONFIG)
    print(f'Smoke household ready: {len(RECIPES)} recipes, menus for {monday - dt.timedelta(days=7)} and {monday}.')
    print(f'Sign in at http://127.0.0.1:5175 as {EMAIL} (see SKILL.md, step 2).')


if __name__ == '__main__':
    main()
