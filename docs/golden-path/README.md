# Golden path — contrato de produto da mesa

Issue: [#2](https://github.com/edsoncantuaria/vtt-edson/issues/2). Este documento define o comportamento-alvo, não declara que a implementação atual está pronta. O roteiro de execução deve ser percorrido integralmente pela interface, usando contas separadas de mestre e jogadores. Os caminhos de código são pontos de partida em `main` (`db71c65`), não evidência de aceite funcional; há trabalho local não commitado em outra worktree.

## Princípios e limites

1. A campanha é o limite de autorização, edição e fontes. O ator é a ficha persistida; o token é sua instância em uma cena. O estado compartilhado, seus efeitos e o histórico têm fonte de verdade no backend, nunca apenas em React.
2. A interface oferece ações pelo nome de jogo, não pede IDs de banco, JSON, DevTools ou chamadas manuais. O mestre pode corrigir e justificar uma regra/resultado sem apagar auditoria.
3. A mesma execução é usada por ficha, monstro, item, macro e hotbar: **Regra → Ação → Alvo → Roll → Resultado → Efeito → Estado → Chat**. O servidor valida permissões, calcula/aplica efeitos e persiste; o cliente exibe estados autorizados.
4. A seleção de edição (2014/2024, quando suportada) e fontes acontece antes das escolhas de personagem. Catálogo disponível ≠ conteúdo importado para a campanha ≠ homebrew; uma cópia mantém origem/versão.
5. Segredos do mestre são filtrados antes de entregar REST, broadcast, export e snapshot ao destinatário. Recuperar ou reconectar consulta novamente autorização e revisão; não restaura informações revogadas de um cache local.
6. Erros são recuperáveis sem quebrar a sessão; repetir um pedido após timeout não duplica dano, consumo, loot, evolução ou mudança de turno. O progresso da jornada não depende de botões de salvar escondidos.

## Mapa navegável

| Capítulo | Passos | Entrega |
| --- | --- | --- |
| [A. Preparar a campanha](01-preparacao.md) | G01–G05 | Campanha, edição/fontes, aventura, cenas e publicação |
| [B. Reunir o grupo](02-grupo.md) | G06–G09 | Entrada, aprovação, personagens, tokens |
| [C. Jogar a sessão](03-jogo.md) | G10–G22 | Exploração, ações, combate, magia, recursos, descanso e loot |
| [D. Continuar e concluir](04-continuidade.md) | G23–G27 | Fechar sessão, retomar, evoluir, continuar e concluir aventura |
| [Demonstração sem ferramentas técnicas](demo-checklist.md) | D01–D31 | Evidência prática para o aceite |
| [Aventura interna de referência](reference-adventure.md) | R01–R12 | Fixture e narrativa para QA, sem material protegido de terceiros |
| [Gates de execução](phase-gates.md) | F01–F09 | Critérios para avançar fases do roadmap |

Em cada passo: **ator** é quem opera; **gatilho/UI** é a entrada do usuário; **persistência** é o contrato de estado; **autorização** é quem pode vê-lo/executá-lo; **falha/retorno** especifica recuperação inclusive depois de recarga. Qualquer passo descrito como **lacuna** deve virar implementação na issue correspondente, não ser simulado no teste final.

## Termos e transições comuns

**GM**: mestre da campanha. **Player**: membro com personagem aprovado/controlado. **Assistant**: papel delegado somente quando houver política explícita. **Preparada**: cena acessível apenas a autorizados; **publicada/ativa**: cena que pode ser exibida aos jogadores, conforme permissão. **Sessão**: intervalo de jogo com marcador de estado e histórico, não é sinônimo de cena. **Aventura**: agrupamento de capítulos/cenas/encontros e conclusão registrada, não equivale a excluir a campanha.

Todas as ações importantes respondem visualmente com `Pendente → Confirmado` ou `Falhou → Tentar novamente/Resolver conflito`; operações destrutivas oferecem confirmação ou desfazer quando seguro. A retomada utiliza estado persistido e autorizado, não reexecuta a última ação. A ausência de capacidade correspondente no backend é bloqueio explícito, não permissão para inventar estado no frontend.

## Evidência mínima

- `apps/api/routes/api.php`, `apps/api/app/Models/`, `apps/api/tests/Feature/` para recursos e autorização.
- `apps/web/src/components/Lobby.tsx`, `TableView.tsx`, `CharacterWizard.tsx`, `ActorSheet.tsx`, `CombatTracker.tsx`, `AdventureImport.tsx`, `CharacterLevelUp.tsx`, `LootManager.tsx` para entradas na UI.
- `packages/core/src/types.ts`, `packages/contracts/scene-state.schema.json`, `apps/api/app/Game/Dice/DiceRoller.php`, `apps/web/src/lib/useSceneSync.ts` para regras, estado e ressincronização.
- Auditorias preexistentes: `docs/vtt-audit-priorities.md`, `docs/vtt-comparison-followup.md`, `docs/compendium-and-house-rules.md`. Elas descrevem partes do trabalho de 13/09/2026 e não certificam a branch atual ou um teste multiplayer.

**Critério final do núcleo:** mestre e quatro jogadores jogam quatro horas, sem solicitar ajuda ao desenvolvedor, sem editar dados fora da UI e sem precisar de outra ferramenta para ficha, dados ou combate. Registrar cada interrupção como issue reproduzível.
