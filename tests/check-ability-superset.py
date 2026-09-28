#!/usr/bin/env python3
"""Fail if the plugin in this repo registers less than any baseline build.

Every ability name, category, input property (with type), and hook present in
a baseline must exist in the current build; no property may change type, no new
required field may appear, and no permission callback may change. Additions are
fine. Baselines in tests/baseline/ are captured with tests/capture-abilities.php
from the build actually deployed on the live sites (2.3.0, measured 2026-09-28
against o-matic.ai, lucidit.io and practicallyadventist.com) and from the
separately built 2.5.0 package. Task #1013: "we are adding to it, not removing
from it."

Run: python3 tests/check-ability-superset.py   (exit 0 = superset)
"""
import glob, json, os, subprocess, sys

here = os.path.dirname(os.path.abspath(__file__))
main = os.path.join(here, '..', 'wordpress-ai-enabler', 'lucid-wp-enabler.php')
cur = json.loads(subprocess.check_output(['php', os.path.join(here, 'capture-abilities.php'), main]))

def norm(x):
    for v in x['abilities'].values():
        if isinstance(v['props'], list):
            v['props'] = {}
    return x

cur = norm(cur)
bad = 0
for path in sorted(glob.glob(os.path.join(here, 'baseline', '*.json'))):
    base = norm(json.load(open(path)))
    name = os.path.basename(path)
    problems = []
    for h in base['hooks']:
        if h not in cur['hooks']:
            problems.append(f'hook {h} removed')
    for n, b in base['abilities'].items():
        c = cur['abilities'].get(n)
        if c is None:
            problems.append(f'{n}: ability removed')
            continue
        if b['category'] != c['category']:
            problems.append(f"{n}: category {b['category']} -> {c['category']}")
        if b['perm'] != c['perm']:
            problems.append(f"{n}: permission {b['perm']} -> {c['perm']}")
        for p, t in b['props'].items():
            if p not in c['props']:
                problems.append(f'{n}: property {p} removed')
            elif c['props'][p] != t:
                problems.append(f"{n}: property {p} {t} -> {c['props'][p]}")
        for r in set(c['required']) - set(b['required']):
            problems.append(f'{n}: new required property {r}')
    added = sorted(set(cur['abilities']) - set(base['abilities']))
    status = 'FAIL' if problems else 'PASS'
    print(f'{status}  vs {name}: {len(base["abilities"])} baseline, {len(cur["abilities"])} current, {len(added)} added')
    for p in problems:
        print('      ' + p)
    bad += len(problems)
sys.exit(1 if bad else 0)
