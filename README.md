# Pereira Oliveira Turismo — Site + Painel de Leads

Site institucional e painel de acompanhamento de leads da **Pereira Oliveira Turismo**
(operadora de viagens em grupo, Florianópolis/SC — *"Realizando sonhos desde 1967"*).

Projeto conduzido pela **Freela In Home**. Contexto completo em [`CLAUDE.md`](CLAUDE.md).

## Estrutura

```
po-tu-project/
├── index.html            ← site (seção a seção, em construção)
├── assets/
│   ├── css/style.css     ← tokens e estilos
│   ├── js/main.js        ← scripts
│   └── images/           ← logo, favicon, capas de roteiro
├── painel/               ← painel de leads (Supabase + login) — a construir
├── enviar.php            ← backend do formulário (PHPMailer + Supabase) — a construir
└── CLAUDE.md             ← documento-cérebro do projeto
```

## Preview

GitHub Pages deste repositório (com `noindex`, apenas acompanhamento).
Produção final em hospedagem cPanel/PHP no domínio `pereiraoliveiraturismo.com.br`.

## Stack

HTML/CSS/JS estático · PHP (form) · Supabase (leads + auth) · Google Fonts (Rubik + DM Sans).
