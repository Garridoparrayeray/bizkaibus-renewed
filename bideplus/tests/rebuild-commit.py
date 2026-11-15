"""El paso de rebuild-schedule.yml que sube geocache.json y sitemap-lastmod.json tras desplegar: sube encima del
master de ese momento aunque haya cambiado, y deja el árbol desplegado como estaba (HEAD, índice y ficheros). Con
clones ligeros (solo bideplus/scripts) y un php de mentira que escribe el sitemap-lastmod.json que le piden.

Uso: python tests/rebuild-commit.py
"""
import os
import stat
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
WORKFLOW = ROOT + '../.github/workflows/rebuild-schedule.yml'
STEP = '      - name: Commit updated geocache and sitemap lastmod'
FAKE_PHP = '''#!/bin/sh
for ARG in "$@"; do
  case "$ARG" in --state=*) echo '{"/stops/1": ["huella", "2026-11-15"]}' > "${ARG#--state=}";; esac
done
'''
results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


def step_script():
    with open(WORKFLOW, encoding='utf-8') as source:
        text = source.read()
    start = text.index('        run: |\n', text.index(STEP)) + len('        run: |\n')
    return '\n'.join(line[10:] for line in text[start:text.index('\n\n', start)].split('\n'))


def git(folder, *args):
    return subprocess.run(['git', '-C', folder, *args], capture_output=True, text=True, check=True).stdout.strip()


with tempfile.TemporaryDirectory() as folder:
    remote, work, other = folder + '/remote.git', folder + '/work', folder + '/other'
    subprocess.run(['git', 'clone', '-q', '--bare', '--shared', os.path.abspath(ROOT + '..'), remote], check=True)
    git(remote, 'branch', '-f', 'master', 'HEAD')
    for path in (work, other):
        subprocess.run(['git', 'clone', '-q', '--shared', '--no-checkout', '-b', 'master', remote, path], check=True)
        git(path, 'sparse-checkout', 'set', 'bideplus/scripts')
        git(path, 'checkout', '-q', 'master')
        git(path, 'config', 'user.name', 'prueba')
        git(path, 'config', 'user.email', 'prueba@localhost')
    os.makedirs(folder + '/bin')
    with open(folder + '/bin/php', 'w', encoding='utf-8') as out:
        out.write(FAKE_PHP)
    os.chmod(folder + '/bin/php', stat.S_IRWXU)
    # Mientras se desplegaba, master ha cambiado (otro commit), y la noche ha visto paradas nuevas.
    with open(other + '/NUEVO.txt', 'w', encoding='utf-8') as out:
        out.write('otro commit\n')
    git(other, 'add', 'NUEVO.txt')
    git(other, 'commit', '-q', '-m', 'otro')
    git(other, 'push', '-q', 'origin', 'master')
    geocache = work + '/bideplus/scripts/geocache.json'
    with open(geocache, encoding='utf-8') as source:
        changed = source.read().rstrip()[:-1] + ', "__prueba": [43.26, -2.93]}\n'
    with open(geocache, 'w', encoding='utf-8') as out:
        out.write(changed)
    head, status = git(work, 'rev-parse', 'HEAD'), git(work, 'status', '--porcelain')
    env = dict(os.environ, RUNNER_TEMP=folder + '/runner', PATH=folder + '/bin:' + os.environ['PATH'])
    os.makedirs(env['RUNNER_TEMP'])
    run = subprocess.run(['bash', '-e', '-c', step_script()], cwd=work, env=env, capture_output=True, text=True)
    check('el paso acaba bien', run.returncode == 0, run.stdout + run.stderr)
    pushed = git(remote, 'show', '--name-only', '--format=%s', 'master').split('\n')
    check('sube geocache.json encima del master de ahora, con su sitemap-lastmod.json',
          pushed[0] == 'chore: update geocache and sitemap lastmod' and 'bideplus/scripts/geocache.json' in pushed
          and 'bideplus/scripts/sitemap-lastmod.json' in pushed
          and git(remote, 'log', '-1', '--format=%s', 'master~1') == 'otro', pushed)
    check('el árbol desplegado sigue igual: HEAD, índice y ficheros',
          git(work, 'rev-parse', 'HEAD') == head and git(work, 'status', '--porcelain') == status
          and git(work, 'worktree', 'list', '--porcelain').count('worktree ') == 1, git(work, 'status', '--porcelain'))

print(f'\nRESULTADO commit tras desplegar: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
