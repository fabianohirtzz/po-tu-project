# Supabase — Pereira Oliveira Turismo

Usa o **projeto compartilhado (hd360/NOX)**. Tabelas com prefixo `po_` para
não colidir com os outros clientes. Storage no bucket `po-imagens`.

## Como aplicar (uma vez)

1. Supabase → **SQL Editor** → cole e rode `schema.sql` (cria tabelas, RLS, bucket).
2. Rode `seed_roteiros.sql` para popular os 5 roteiros atuais.
3. **Auth → Users** → crie o usuário do painel (e-mail + senha da Pereira Oliveira).
   Só esse usuário logado lê/edita leads e roteiros.

## Chaves

- **Project URL** e **anon key**: públicas (rodam no navegador), já em
  `assets/js/po-config.js` e `painel/config.js`. Segurança = RLS + login.
- **service_role key**: SEGREDO. Só no servidor, dentro de `config.local.php`
  (ver `enviar.php`). Nunca versionar.

## Modelo de segurança (RLS)

- `po_leads`: **anon** só pode INSERT (formulário público). Ler/editar/excluir
  exige login. Ou seja, um visitante grava o próprio lead mas não vê os dos outros.
- `po_roteiros`: **anon** lê só os `ativo = true` (site público). Painel (logado)
  faz CRUD completo.
- `po_ad_spend`: só logado.
- Storage `po-imagens`: leitura pública, upload só logado.

## Tabelas

- **po_leads** — leads do site (mesmos campos do padrão NOX + `roteiro`, `viajantes`).
- **po_roteiros** — cada roteiro do site (título, subtítulo, dias, período, roteiro
  dia-a-dia em `roteiro_dias` jsonb, `inclui`/`nao_inclui`, `valores`, `galeria`,
  `capa_url`, `video_id`, `ativo`, `ordem`).
- **po_ad_spend** — investimento mensal em anúncios (relatórios: CPL/ROAS/ROI).
