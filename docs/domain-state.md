# Modelo de domínio e fontes de verdade (issue #3)

Contrato para o pipeline `Regra → Ação → Alvo → Roll → Resultado → Efeito → Estado → Chat`. Este documento descreve o domínio e as responsabilidades; não assume que os fluxos ainda pendentes já estão implementados. A especificação da jornada está em [golden-path](golden-path/README.md).

| Entidade | Identidade/escopo e persistência | Fonte de verdade e invariantes |
| --- | --- | --- |
| Campaign/Room | `campaigns`, `rooms`, `campaign_members`, `scene_members` | Campanha delimita edição, fontes e autorização; Room oferece código e cena ativa, não duplica dados de personagem. |
| Actor | `actors.id`, `campaign_id`, `system`, `revision`, dono | Ficha de personagem/NPC/criatura independente do mapa; `system.hp.value/max/temp`, recursos, slots, atributos, condições livres e concentração. Atualização de ficha com revisão esperada. |
| Token | `scenes.state.tokens[].id`, `actorId` opcional | Instância visual e posicional da cena: coordenadas, imagem, tamanho, controlador, hidden. Um token com ator não mantém outra cópia persistida de PV/recursos; a ficha do ator é consultada. Dois tokens de um mesmo ator compartilham a ficha, mas posições diferentes. Para dois monstros com HP independente, criar **atores independentes** a partir do template. |
| Scene | `scenes`, `state` JSON, `campaign_id` | Mapa, grade, fog, luzes, paredes, portas, tokens, objetos e chat da cena. Toda mutação passa pela API e autorização; `SceneStateFactory` e `SceneStateSchema` definem formato inicial. |
| CatalogEntry/source | `catalog_entries`, `campaign_catalog_sources`, materiais/compartilhamentos | Fonte, edição, slug e versão identificam regra disponível; referência importada preserva origem e não substitui homebrew. |
| Classe/subclasse/raça/sub-raça/background/feat | `catalog_entries.data`, seleção em `actors.system` | Opções vêm do catálogo conforme edição e fonte. Snapshot de escolha no ator precisa manter origem; não confundir referência com recurso gasto. |
| Item/Spell/Feature | `actor_documents`, opcional `catalog_entry_id`, `data` + `overrides` | Documentos são registros do inventário, magias conhecidas e features; `actors.system.inventory`, `spells.known` e `features` são projeções legadas de compatibilidade. Quantidade é inteiro positivo; spells slots estão no `actor.system`. |
| Conditions/ActiveEffect | `actors.system.conditions`, `active_effects`, ator alvo | Condição manual é lista da ficha; efeito com origem, duração e modificadores é registro independente. Token mostra projeção autorizada, nunca cria uma segunda condição canônica. |
| Resource | `actors.system.resources`, `spells.slots`, `actor_documents.charges` | Usado/máximo/recuperação pertencem ao ator ou documento e satisfazem `0 ≤ used ≤ max`. Não delegar controle da verdade ao frontend. |
| Encounter/Combat | `encounter_builder_drafts`, `combats`, `combat_participants`, token ref | Encontro é preparação; combate é evento/ordem corrente, participantes apontam para ator/token válidos na campanha/cena, sem novos PV próprios. |
| Journal/Handout | `journals`, recursos/compartilhamentos da campanha | Conteúdo com permissões de leitura/controle; pins são referências visuais e não autorizam acesso ao texto privado. |
| Action/Roll/Effect/Chat | `action_records`, `damage_applications`, estado/registro de cena, `active_effects` | Operação persistida e idempotente; efeito altera ator e produz histórico autorizado, sem reexecutar roll após refresh. |

## Compatibilidade e migração

- Cenas novas têm `schemaVersion: 2`. Cenas V1 sem marcador continuam válidas e são lidas como versão 1; importação de backups mantém os dados originais. O contrato JSON e o schema Zod reconhecem explicitamente ambas as versões. A migração não é executada automaticamente em uma instalação com dados existentes.
- `php artisan scene-state:upgrade` lista as cenas elegíveis sem mutar dados; `--apply` faz o backfill, guardando o JSON anterior em `scene_state_upgrade_backups`, e `--restore` o restaura somente se a cena não mudou depois do upgrade. `--scene=ID` restringe a intervenção de manutenção a uma cena; executar restauração antes de reverter a migration da tabela de backups. O comando é idempotente.
- Cenas antigas com `token.combat` duplicando estado de ator **não são convertidas automaticamente**: o comando sinaliza conflito e preserva os bytes. O responsável deve conciliar separadamente cada token com o HP atual, HP temporário, recursos e efeitos do ator, criando atores clones distintos quando a intenção for manter HP independente. Não atribuir precedência silenciosa à projeção antiga.
- Novos tokens de personagem sem `ownerUserId` explícito herdam o proprietário do ator; os tokens existentes não são reatribuídos silenciosamente. GM pode delegar controle explicitamente quando a regra permitir; `ownerUserId` não equivale à autorização de editar a ficha.
- `ActorDocumentService::syncFromLegacy()` preserva correspondência por `documentId` quando presente e nunca mapeia duas entradas distintas ao mesmo documento numa sincronização. Documentos preexistentes conservam `catalog_entry_id`, `source`, overrides e cargas. Não reescrever materializações em massa sem prévia/backup.
- O progresso do contrato não equivale a executar a migração numa base de produção: primeiro gerar backup da campanha, fazer a prévia, revisar os conflitos e só depois aplicar. Carga simultânea em PostgreSQL e convergência após queda de websocket devem ser exercitadas em um ambiente com o serviço disponível.

## Testes mínimos de domínio

`packages/core/src/dnd.test.ts`, `apps/api/tests/Feature/ActorTest.php`, `SceneTokenTest.php`, `DamageApplicationTest.php`, `SceneWriteTransactionTest.php`. Cobrir entradas negativas, excesso de consumo, duas instâncias, isolamento de campanha, concorrência na revisão de ficha, retriable operations e restauração de campanha. Testar também API real no PostgreSQL (SQLite de teste não prova lock concorrente).
