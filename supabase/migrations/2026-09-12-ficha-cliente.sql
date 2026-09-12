-- Bloco de ficha do cliente adotado do CRM antigo (spec 4.1). Tudo opcional,
-- nada removido nem renomeado: os relatorios existentes nao mudam.
alter table public.po_leads add column if not exists cpf text;
alter table public.po_leads add column if not exists rg text;
alter table public.po_leads add column if not exists data_nascimento date;
alter table public.po_leads add column if not exists nacionalidade text;
alter table public.po_leads add column if not exists estado_civil text;
alter table public.po_leads add column if not exists profissao text;
alter table public.po_leads add column if not exists cep text;
alter table public.po_leads add column if not exists endereco text;
alter table public.po_leads add column if not exists numero text;
alter table public.po_leads add column if not exists complemento text;
alter table public.po_leads add column if not exists bairro text;
alter table public.po_leads add column if not exists estado text;
alter table public.po_leads add column if not exists passaporte_numero text;
alter table public.po_leads add column if not exists passaporte_orgao_emissor text;
alter table public.po_leads add column if not exists passaporte_emissao date;
alter table public.po_leads add column if not exists passaporte_validade date;
alter table public.po_leads add column if not exists contato_emergencia_nome text;
alter table public.po_leads add column if not exists contato_emergencia_telefone text;
alter table public.po_leads add column if not exists contato_emergencia_parentesco text;
alter table public.po_leads add column if not exists telefone_secundario text;
alter table public.po_leads add column if not exists observacoes text;
alter table public.po_leads add column if not exists origem_import text;
alter table public.po_leads add column if not exists payload_import jsonb;
alter table public.po_leads add column if not exists revisado boolean not null default false;
alter table public.po_leads add column if not exists cliente boolean not null default false;

-- CPF e a chave de identidade mais confiavel na fusao (spec 9.2). Guardado
-- so em digitos (o importador normaliza). O indice e PARCIAL: os leads do
-- site nascem sem cpf e nao podem colidir entre si por causa do vazio.
create unique index if not exists po_leads_cpf_uniq
  on public.po_leads (cpf) where cpf is not null and cpf <> '';
