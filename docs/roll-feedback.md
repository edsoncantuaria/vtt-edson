# Feedback de dados — issue #9

A interface mostra **Rolando…** imediatamente no painel de dados e **Enviando…** no chat enquanto aguarda o servidor. Não há sorteio nem total provisório no navegador: resultado, expressão e detalhe exibidos são os valores já persistidos pela API da issue #8.

Na chegada de uma nova rolagem pública ou ação no `scene.state.chat`, aparece sobre o mapa um card compacto com nome, fórmula, detalhe dos dados, total e indicação discreta de 20/1 natural. Ele desaparece após 1,8 segundo; a animação CSS dura 220 ms, não captura cliques na mesa e pode ser dispensado. O resultado completo permanece no chat. O card não reapresenta registros existentes quando a cena carrega, recebe um snapshot repetido ou reconecta. Uma rolagem reservada ao GM recebe feedback **somente no cliente autorizado que enviou a requisição**; não é publicada no chat nem emitida no websocket público, e seu `requestId` continua idempotente.

No painel **Dados da mesa**, as preferências locais permitem desativar animação e ativar/desativar um som curto, desligado por padrão. `prefers-reduced-motion: reduce` sempre cancela a animação. Quando a animação está desligada, o aviso mostra imediatamente o mesmo resultado estático. Nenhum arquivo remoto de áudio/imagem é necessário e falhas de reprodução não impedem a rolagem.

O card permanente do chat identifica ação, expressão, detalhe/resultado e crítico/falha, inclusive o resultado secundário de dano. **Acerto/falha contra CA** aparece somente na prévia de resolução de dano, depois que o jogador autorizado ou GM escolhe um ator controlável e o backend devolve o cálculo; CA não é adicionada à mensagem pública nem inferida de fichas de terceiros. Este feedback não altera mecânica, rolagem, aplicação de dano ou visibilidade do ledger.

Testes: `rollFeedback.test.tsx` cobre persistência de preferências, defaults silenciosos, renderização de fórmula/dados/total, tags, deduplicação de snapshots/reconexão e modo estático. `scene.test.ts` cobre o aviso local de rolagem GM sem vazamento para chat e sem repetição em retry. Os testes de resolução/autorizações da API continuam validando o acesso à prévia de alvos.
