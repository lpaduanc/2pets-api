# 2pets — API (Laravel 12)

Ambiente, portas e comandos do dia a dia: `../README.md`.

## Testes: nunca no banco de dev

A suite usa `RefreshDatabase`, que roda `migrate:fresh` e apaga todas as tabelas do banco
configurado. O banco de teste (`twopets_test`) vem do `phpunit.xml` (`DB_CONNECTION=pgsql_test`),
mas esse `<env>` não tem `force`: uma variável de ambiente `DB_CONNECTION` já existente no
processo vence, e a suite apaga o `twopets` (dev). Aconteceu em 2026-10-25.

- Rode assim: `docker compose exec -T api php artisan test --filter=...` (o container lê o
  `.env` como arquivo, então o `phpunit.xml` vence).
- Nunca passe o `.env` como variável de ambiente: nada de `docker run --env-file .env`,
  `export $(cat .env)`, `DB_CONNECTION=pgsql php artisan test` ou `--env=local`.
- Fora do container `api` (ex.: `git worktree`): monte o `.env` como arquivo
  (`-v .../2pets-api/.env:/app/.env:ro`) **e** passe `-e DB_CONNECTION=pgsql_test`. Comando
  completo no `../README.md`.
- Se o banco de dev for apagado: `docker compose exec api php artisan db:seed --force` recria a
  demonstração; dados criados à mão não voltam.
