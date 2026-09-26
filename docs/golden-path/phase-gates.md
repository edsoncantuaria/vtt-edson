# Gates de evolução do VTT

[Índice](README.md) · [Miniaventura](reference-adventure.md)

Avançar fase requer demonstração dos casos abaixo e evidência em PR. Issue/documento existente não equivale a funcionalidade concluída; testes devem usar duas sessões autenticadas independentes para verificar permissões.

| Gate | Issues/escopo | Prova de passagem |
| --- | --- | --- |
| F01 Fundamento | #2–#4 (etapas 01–03) | Contrato G01–G27 publicado, esquema e ownership documentados, busca por edição/fonte e separação Biblioteca/Na campanha/Homebrew sem perda de origem. |
| F02 Personagem/dados | #5–#9 (04–08) | Criar personagem completo e evoluir com a mesma engine; clicar ficha produz roll persistido único e chat consistente. |
| F03 Ações/efeitos | #10–#17 (09–16) | Ataque, save, dano/cura, condição e recurso usam um executor comum; GM pode corrigir; repetição não aplica duas vezes. |
| F04 Mesa/magia | #18–#23 (17–22) | Fireball/área, visão segura, medição/movimento, tokens independentes e mesa utilizável em mouse/touch. |
| F05 Combate/sessão | #24–#31 (23–30) | Monstro jogável, iniciativa e turnos estáveis, hotbar, chat privado e handout; nenhum segredo em API/broadcast. |
| F06 Campanha longa | #32–#41 (31–40) | Importação revisável, encontros, inventário, descanso, morte, XP/milestone e conclusão/reabertura de aventura sem sair da UI. |
| F07 UX/robustez | #42–#50 (41–49) | 390×844 e 820×1180, feedback, save/reload, desconexão, concorrência, desfazer e restauração de backup testados. |
| F08 Evidência de uso | #51–#55 (50–54) | E2E R01–R12, playtest solo e com GM + quatro jogadores, com métricas de atrito; quatro horas sem assistência técnica. |
| F09 Diversão opcional | #56–#57 (55–56) | Áudio/efeitos opcionais com reduced motion, sem bloquear, regredir ou aumentar o tempo do fluxo principal. |

Os números `#` referem-se a issues GitHub criadas a partir das 56 etapas; o roadmap guarda-chuva é #1. Se uma lacuna transversal bloquear o fluxo antes de sua fase, abrir defeito vinculado e resolver somente o mínimo necessário para o gate atual, sem transformar a issue #2 numa reescrita de produto.

## Padrão de prova por PR

- Estado inicial, contas/papéis, edição/fontes, comandos de teste executados e resultado.
- Demonstração por UI (captura ou roteiro reproduzível) com fluxo de sucesso, cancelamento/erro, reload e permissão negativa.
- Entidades afetadas, migração e compatibilidade de dados existentes; contrato da API/evento quando aplicável.
- Nenhum estado crítico apenas no React, nenhum dado secreto em payload não autorizado, nenhum efeito duplicado por retry.
- Testes de unidade/integrados/E2E relevantes e evidência manual onde a automação não está pronta.
