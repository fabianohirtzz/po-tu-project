# Painel do funil e ficha de cliente — pendências conhecidas

> Registrado em 11/09/2026, ao fim do plano
> `docs/superpowers/plans/2026-09-11-painel-funil-clientes.md`.
> Nada aqui bloqueia o merge.

## Vale fazer antes do merge (2 linhas, risco zero)

1. **O teste do vazamento de conversa não cobre o ponto de chamada.**
   O filtro `.eq('wa_id', waId)` já sumiu uma vez nesta branch, num commit que
   prometia outra coisa, e a consulta passou a devolver as mensagens de todos os
   contatos. O teste que trava isso hoje olha só as funções puras
   (`poFiltroConversa`, `poMontaConsulta`): reescrever a chamada inline em
   `poRenderConversa`, deixando as funções intactas, **reintroduz o defeito com o
   teste verde**. É exatamente a forma do incidente original.

   Duas asserções sobre o fonte fecham, no molde que `tests/test-filtros.mjs`
   já usa:

   ```js
   assert.ok(src.includes('poMontaConsulta(sb, poFiltroConversa(waId))'),
     'o render usa o descritor, nao consulta inline');
   assert.ok(!/sb\.from\('po_wa_mensagens'\)/.test(src),
     'nenhuma consulta inline a po_wa_mensagens fora do poMontaConsulta');
   ```

   O mesmo buraco existe em `tests/test-gaveta.mjs` para a regra do `venda_at`.

## Dívida aceita

2. **`poChavePessoa` é um segundo normalizador de telefone**, divergente do
   `wa_e164()` que o projeto já tem em `lib/wa-fone.php`: sem a regra do nono
   dígito, sem o `+`. Um celular antigo de 8 dígitos vira ficha separada da mesma
   pessoa. A correção é portar o normalizador para JS, e o próximo plano
   (importador de contatos) vai precisar de um de qualquer forma. Que nasça um só,
   lá, e que as duas telas passem a usá-lo.

3. **Acessibilidade da gaveta de navegação no celular:** fechada, ela sai por
   `translateX(-100%)` sem `visibility:hidden`, então os cinco itens de menu
   continuam no ciclo do Tab, invisíveis. Falta também `Escape` para fechar, e ela
   não se fecha sozinha ao passar de 980px (girar o aparelho deixa o scrim ligado,
   recuperável com um clique).

4. **O seletor de mês continua visível na aba Clientes**, que agora o ignora de
   propósito (uma base de clientes não é recorte mensal). O controle parece vivo e
   não faz nada. O padrão de esconder já existe na linha ao lado, para Roteiros.

5. **As asserções de fonte em `tests/test-filtros.mjs` dependem da formatação
   exata** do `app.js`. Reformatar o arquivo deixa o teste vermelho sem que nada
   tenha mudado de comportamento. Num projeto sem formatador automático o risco é
   baixo, e é a única coisa que cobre a ligação entre cada tela e o seu recorte.

6. **Menores:** `poPessoas()` recalcula duas vezes ao abrir a ficha; a guarda
   `typeof l !== 'object'` deixa passar `Array` e `Date`; status `'__proto__'`
   quebraria o agrupamento do funil; notebook com tela de toque híbrida cai no modo
   desktop e o toque não dispara arrastar nativo; a trava `poMovendo` não usa
   `try/finally` (mesmo padrão do resto do `app.js`).

## Mudança de comportamento deliberada, para ninguém estranhar

7. **Marcar como "perdido" um lead que tem valor de venda agora funciona.** Antes,
   o `ven > 0` empurrava o status de volta para "venda" ao salvar. É a mesma regra
   que o funil já aplica ao tirar o card de "Contrato assinado", e o valor continua
   guardado no lead.
