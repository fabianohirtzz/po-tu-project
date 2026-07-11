<?php
/* ============================================================
   MODELO — NÃO contém segredo. Copie para "config.local.php"
   (mesma pasta) NO SERVIDOR e preencha os valores reais.
   O config.local.php NÃO é versionado (.gitignore) nem tocado
   pelo deploy. Assim os segredos vivem só no servidor.
   Sobrescreve qualquer variável de CONFIG do enviar.php.
============================================================ */

// senha da conta de e-mail (SMTP)
$SMTP_PASS = 'COLOQUE_AQUI_A_SENHA_DO_EMAIL';

// Supabase — service_role key (SEGREDO: acesso total, ignora RLS).
// Supabase → Project Settings → API → "service_role" (projeto hd360/NOX).
$SUPABASE_SERVICE_KEY = 'COLOQUE_AQUI_A_SERVICE_ROLE_KEY';
