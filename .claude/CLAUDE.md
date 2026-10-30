# Bide+ · instrucciones para Claude

## Rama: siempre `centro-de-pruebas`

- Todo el trabajo va siempre en la rama `centro-de-pruebas`, sea cual sea el cambio y aunque la sesión proponga otra
  (`claude/...`). No crees ni subas ramas con otro nombre.
- Al empezar: `git fetch origin master centro-de-pruebas`. Si la última PR de `centro-de-pruebas` ya está fusionada, rehaz
  la rama desde master con `git checkout -B centro-de-pruebas origin/master`; si solo tenía historia ya fusionada, súbela
  con `git push --force-with-lease`.
- Sube con `git push -u origin centro-de-pruebas` y abre la PR de `centro-de-pruebas` a `master` como borrador.

## Autoría

- Todos los commits a nombre de `Garridoparrayeray <garridoparrayeraytx@gmail.com>`.
- Sin líneas `Co-Authored-By` ni `Claude-Session` en los commits, y sin pie de «Generated with Claude Code» en las PR.
- No escribas comentarios, revisiones ni respuestas en GitHub: lo que haya que decir, por el chat.
