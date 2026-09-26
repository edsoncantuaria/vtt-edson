# Roteiro de demonstração do golden path

[Índice](README.md) · [Aventura de referência](reference-adventure.md) · [Gates](phase-gates.md)

Execute em browser com contas separadas de GM e quatro players; edição/fontes configuradas na própria interface. O observador pode coletar logs e evidência depois, mas **o usuário não pode usar IDs manuais, editar JSON, abrir DevTools, chamar endpoint, banco ou outro software para ficha/dados/combate**. Registre `PASS`, `FAIL` (com reprodução/issue) ou `BLOCKED` (pré-requisito faltante) para cada caso. Não transformar `BLOCKED` em aprovação. Recarregue GM e um player após pelo menos um evento crítico e confira estado e sigilo.

| Caso | Ação a demonstrar | Esperado / vínculo |
| --- | --- | --- |
| D01 | GM cria campanha no Lobby. | Campanha aparece com código/GM sem ID técnico; G01. |
| D02 | GM escolhe edição e fontes e busca conteúdo na Biblioteca. | Fontes legíveis, edição consistente e estado salvo; G02. |
| D03 | GM importa/cria aventura e revê referências de capítulos. | Cenas/notas ordenadas, conteúdo ausente sinalizado; G03. |
| D04 | GM prepara mapa/grid, porta, fog, luz e encontro. | Cena salva e privada antes da publicação; G04. |
| D05 | GM publica e ativa só a primeira cena. | Todos veem primeira cena; segunda privada nunca chega aos players; G05. |
| D06 | Quatro jogadores entram por código. | Participações distintas e autorizadas; G06. |
| D07 | Cada jogador cria/importa ficha pela UI. | Escolhas obrigatórias satisfeitas e ficha persistida; G07. |
| D08 | GM aprova/solicita correção de uma ficha. | Controle só após aprovação, decisão auditada; G08. |
| D09 | GM arrasta quatro personagens e duas criaturas do mesmo template. | Tokens separados e ownership correto; G09. |
| D10 | Player move próprio token; tenta mover token de outro. | Próprio movimento salvo; outro negado pelo servidor; G10. |
| D11 | GM abre porta, revela área e mostra handout público. | Player recebe somente conteúdo revelado; G10–G11. |
| D12 | Player clica perícia e rola com vantagem/desvantagem. | Roll único, nome/fórmula/total no chat; G12. |
| D13 | GM rola teste secreto e inspeciona como player. | Sem roll privado via UI, REST ou Reverb; G12/G22. |
| D14 | GM inicia combate, jogadores rolam e GM rola NPCs em lote. | Ordem e rodada estáveis; G13. |
| D15 | Guerreiro clica ataque e escolhe um alvo. | Hit/miss autorizado, roll persistido; G14. |
| D16 | Aplicar dano, resistência, cura e HP temporário. | Cálculo visível, HP correto, reaplicar é rejeitado; G15. |
| D17 | Aplicar condição com duração. | Ícone/ficha/roll alterado e expiração correta; G16. |
| D18 | Lançar magia de área e resolver saves dos quatro alvos. | Resultado por alvo, GM confirma efeitos; G17. |
| D19 | Gastar slot e outro recurso; cancelar uma magia antes de confirmar. | Custo exato, nenhum débito no cancelamento; G18. |
| D20 | Encerrar dois turnos e finalizar combate. | Duração e ordem corretas, sem processar duas vezes; G19. |
| D21 | Descansar curto e longo com prévia. | Recuperação esperada e opção de cancelar; G20. |
| D22 | Distribuir saque, equipar/consumir item. | Quantidade, dono, moeda e efeito coerentes; G21. |
| D23 | Ler histórico público e whisper. | Cards legíveis, nenhum segredo de outra pessoa; G22. |
| D24 | GM encerra sessão, sem encerrar campanha. | Checkpoint/histórico preservado; G23. |
| D25 | Logout, reload, queda de websocket e retorno no outro dia. | Mesma cena/HP/turno/estado autorizado; G24. |
| D26 | GM concede milestone/XP, player evolui. | Engine resolve escolhas, nível/recursos persistem; G25. |
| D27 | GM ativa próxima cena e decide como portar tokens. | Transição explícita e sem teleport surpresa; G26. |
| D28 | GM conclui aventura e consulta seu histórico. | Aventura encerrada sem apagar campanha/PCs; G27. |
| D29 | Repetir uma mutation após timeout e recarregar dois clientes. | Sem roll, dano, recurso, loot ou turno duplicado. |
| D30 | Revogar acesso de player e tentar URL anterior/estado cacheado. | Sem segredos em API, realtime, export ou cache após resync. |
| D31 | Medir sessão completa com GM + quatro jogadores. | Quatro horas sem pedir ajuda ao dev nem recorrer a ferramentas externas para ficha/dados/combate. |

Para cada falha registrar: caso, papel, edição, cena, ação esperada, comportamento real, captura, estado após refresh e issue vinculada. O teste D31 é gate de produto, não condição de aceite da documentação na issue #2: esta issue define o contrato executável que será realizado nas issues seguintes.
