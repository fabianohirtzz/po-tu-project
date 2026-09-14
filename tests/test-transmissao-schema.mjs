/* A reserva-antes-do-envio da Task 5 usa o INDICE UNICO como cadeado: o
   insert que conflita devolve null e o destinatario e pulado. Sem o indice,
   dois drenos simultaneos (o cron e o botao do painel) mandam a mesma
   campanha duas vezes para a mesma pessoa, a R$ 0,31 cada, e nada acusa. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const sql = readFileSync(
  join(raiz, 'supabase/migrations/2026-09-14-transmissao.sql'), 'utf8').toLowerCase();

assert.ok(/create table if not exists public\.po_wa_campanhas/.test(sql),
  'cria po_wa_campanhas');
assert.ok(/create table if not exists public\.po_wa_envios/.test(sql),
  'cria po_wa_envios');

// O cadeado da Task 5. O `on public.po_wa_envios` faz parte da assercao: um
// indice com o nome certo na tabela errada nao tranca nada, e passava.
assert.ok(/create unique index if not exists po_wa_envios_camp_lead_uniq\s+on\s+public\.po_wa_envios\s*\(campanha_id, lead_id\)/.test(sql),
  'po_wa_envios tem unique (campanha_id, lead_id), que e o cadeado da reserva');

// A chave do recibo de entrega da Task 7, tambem presa a tabela.
assert.ok(/create unique index if not exists po_wa_envios_wamid_uniq\s+on\s+public\.po_wa_envios\s*\(wamid\)\s*where wamid is not null/.test(sql),
  'po_wa_envios tem unique parcial em wamid, que e por onde o recibo acha o envio');

// A defesa 3 da spec 8.1 mora em po_leads, nao em po_wa_contatos (ver as
// decisoes de arquitetura do plano).
assert.ok(/alter table public\.po_leads[\s\S]*?add column if not exists opt_out_at/.test(sql),
  'opt_out_at entra em po_leads');
/* Proibe DDL contra po_wa_contatos, nao a MENCAO: o comentario de cabecalho
   da migration cita a tabela para registrar por que a spec 4.2 foi
   contrariada, e esse registro tem que continuar no arquivo. */
assert.ok(!/(create|alter|drop)\s+table\s+(if\s+(not\s+)?exists\s+)?(public\.)?po_wa_contatos/.test(sql),
  'a migration NAO cria nem altera po_wa_contatos, que esta abandonada');

// FK com o tipo certo: todas as tabelas do projeto usam uuid/gen_random_uuid.
/* Tolerante a espaco: o projeto alinha as colunas do create table em coluna
   (visivel em toda a migration), entao "lead_id" e "uuid" ficam separados por
   varios espacos de alinhamento, nao um so. O que importa e o tipo (uuid) e a
   referencia (public.po_leads(id)), nao a largura do espacamento. */
assert.ok(/lead_id\s+uuid\s+not\s+null\s+references\s+public\.po_leads\(id\)/.test(sql),
  'lead_id e uuid e referencia po_leads');

// O nome e o parametro {{1}} do template. Sem a coluna, o dreno manda vazio
// e o cliente recebe "Ola ,".
assert.ok(/nome\s+text\s+not null default ''/.test(sql),
  'po_wa_envios guarda o nome fotografado na reserva');

/* Contador copiado desanda em silencio: enviados/entregues/lidos/falhas sao
   CONTADOS de po_wa_envios, nunca guardados em po_wa_campanhas. */
assert.ok(!/^\s*(enviados|entregues|lidos|falhas)\s+integer/m.test(sql),
  'po_wa_campanhas NAO tem contador copiado de entrega');

// RLS ligada, no padrao das tabelas existentes (<tabela>_auth). Ancorado no
// inicio da linha (^...m) para nao casar com a linha comentada.
assert.ok(/^alter table public\.po_wa_campanhas\s+enable row level security/m.test(sql),
  'RLS ligada em po_wa_campanhas');
assert.ok(/^alter table public\.po_wa_envios\s+enable row level security/m.test(sql),
  'RLS ligada em po_wa_envios');

console.log('test-transmissao-schema OK');
