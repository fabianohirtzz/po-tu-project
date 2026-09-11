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

// para onde o lead chega. Descomente para trocar sem mexer no enviar.php.
// O remetente continua sendo a conta autenticada no SMTP (site@...), não este.
// $DESTINO = 'poturismo@poturismo.com.br';

// Supabase — service_role key (SEGREDO: acesso total, ignora RLS).
// Supabase → Project Settings → API → "service_role" (projeto hd360/NOX).
$SUPABASE_SERVICE_KEY = 'COLOQUE_AQUI_A_SERVICE_ROLE_KEY';

// Gemini — chave da API do importador de roteiros (importar.php).
// Crie GRÁTIS em https://aistudio.google.com/apikey (NÃO é a assinatura
// "Gemini Advanced"; é a API do Google AI Studio, tier gratuito).
$GEMINI_API_KEY = 'COLOQUE_AQUI_A_CHAVE_DO_GEMINI';

// --- WhatsApp Cloud API -------------------------------------------
// Painel do app em developers.facebook.com. Todos sao SEGREDO: este
// arquivo tem uma copia real (config.local.php) que nunca vai pro Git.
$WA_TOKEN        = '';  // token permanente do System User
$WA_PHONE_ID     = '';  // Phone Number ID (nao e o telefone)
$WA_APP_SECRET   = '';  // App Secret, usado para validar a assinatura
$WA_VERIFY_TOKEN = '';  // string inventada por nos, repetida no cadastro do webhook
$WA_CRON_KEY     = '';  // string inventada por nos, protege wa-cron.php pela web
