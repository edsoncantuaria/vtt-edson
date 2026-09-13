# Auditoria de lacunas do VTT

Data: 2026-09-13. Escopo: auditoria funcional do código presente no workspace, incluindo alterações ainda não commitadas. Base Git: `6a6491c`; clone 5etools: `e5d052071b635f58cc8006e9727053eaf78ea8f9`. Este documento não certifica produção nem substitui teste de uma sessão multiplayer. Não foram implementadas funcionalidades durante esta auditoria. Comparação externa: [referências oficiais](vtt-competitor-references.md).

## Ordem recomendada

Esforço relativo: baixo = extensão localizada; médio = integrações em algumas camadas; alto = mudança transversal e testes de sessão. Não são estimativas de prazo. P0 precede expansão; P1 completa o jogo; P2 melhora preparação; P3 é opcional.

| Prioridade | Lacuna frente aos VTTs de referência | Estado real aqui | Recomendação e trade-off | 5etools ajuda? |
|---|---|---|---|---|
| P0.1 | Separação de segredos do mestre e permissões por documento | API de cenas e broadcast entregam o estado completo aos membros; API de atores lista todas as fichas da campanha. Notas privadas têm filtro próprio, mas névoa não protege o payload de tokens/fichas. | Filtrar payload por destinatário, distinguir preparar/publicar cena e controlar leitura de NPCs. Alto esforço; evita revelar encontros e exige definir o que é informação pública. | Não: é responsabilidade do VTT. |
| P0.2 | Persistência confiável em sessão simultânea | Várias mutações leem e regravam o JSON inteiro da cena; serialização no navegador não resolve duas pessoas gravando ao mesmo tempo. Fichas aceitam `system` como array e substituem o conjunto. Diário já possui versão/409. | Validar schema no servidor e adotar revisão/transação nos agregados. Médio/alto; menos sobrescritas, mais tratamento explícito de conflito. | Não. |
| P1.1 | Ação completa: ataque, dano, salvaguarda, crítico e recursos | Endpoint escolhe `attackFormula ?? damageFormula`; não resolve ataque e dano juntos, alvos, resistência ou consumo automático de espaços. | Primeiro cartão de ação com resultados separados e aplicar dano mediante escolha; depois condições/concentração e recursos. Alto; automação reduz cliques, mas decisões precisam continuar reversíveis pelo mestre. | Sim: dados de magias e ataques; regras de execução precisam ser nossas. |
| P1.2 | Régua, áreas de efeito, alvos e movimento | Há grade/encaixe e arrastar tokens; não há ferramenta dedicada para cone, círculo, linha, distância ou seleção de alvos. | Medição e templates antes de efeitos visuais. Médio; enorme valor em combate, exige unidades, diagonais e revisão 2014/2024 explícitas. | Alcance/área das magias ajudam; geometria é local. |
| P1.3 | Visão por token, colisão e portas avançadas | Paredes/portas bloqueiam raios visuais; luzes entram como fontes globais de revelação e tokens têm raio fixo. Sem darkvision ligado à ficha, colisão de movimento, portas trancadas/secretas. | Separar iluminação de percepção e validar movimento. Alto; mais custo de CPU e necessidade de testar mobile. | Sentidos e deslocamentos de criaturas ajudam, não fornecem motor de visão. |
| P1.4 | Criação e evolução de personagem completas | Wizard de nível 1; equipamento/magias/escolhas específicas ficam manuais. Fichas rápida e clássica existem, incluindo aba separada. | Completar escolhas obrigatórias do nível 1 e só depois level-up/multiclasse. Alto; evita apresentar como válida uma ficha incompleta. | Classes, subclasses, features, progressões, perícias e origens; escolhas precisam de modelo próprio. |
| P1.5 | Jogo confortável no tablet/mobile | CSS responsivo e eventos pointer existem; não equivalem a QA em dispositivo nem a gesto multitoque completo. | Validar criar ficha, rolar, mover, consultar e fechar painel com toque. Médio; priorizar um painel por vez e acesso ao mapa. | Não. |
| P2.1 | Cenas em preparação, ativação do grupo e organização | Criar/trocar/renomear cenas existe; membros recebem novas cenas. Sem fluxo de rascunho/publicação, pastas, duplicação ou trazer grupo para cena ativa. | Organizar cenas por campanha e separar cena preparada da exibida. Médio; controles extras ficam em Preparar. | Aventuras podem fornecer nomes e estrutura. |
| P2.2 | Aventura importada pronta para jogar | Capítulos de livros/aventuras importados; leitor mostra placeholders para imagens e referências de stat block. Não cria mapas, paredes, luzes, encontros ou notas automaticamente. | Assistente por capítulo: prévia → escolher mapas/criaturas/notas → ajustar grade → revisar/publicar. Alto; importar texto é barato, inferir geometria confiável não. | Sim, parcialmente: mapas/referências/texto. Não presumir coordenadas de paredes e tokens prontas. |
| P2.3 | Consulta contextual e biblioteca enxuta | Fontes por campanha, PHB/XPHB para leitura e capítulos compartilhados existem. Referências são achatadas em texto; conteúdo copiado para a ficha não é vínculo vivo. | Quick reference, busca por referência e painel lateral; preservar origem/versão ao copiar. Médio; atualização nunca deve reescrever homebrew silenciosamente. | Alto aproveitamento. |
| P2.4 | Homebrew selecionável, versionado e compartilhável | Edição local de ações/itens e regras da casa por correspondência de nome existem. Sem catálogo homebrew por campanha nem revisão de pacotes. | Importar verbete isolado com prévia e fonte própria; habilitar por mesa. Médio; validação e conflitos antes de suporte a scripts. | Formato homebrew e referências podem ajudar; não executar código importado. |
| P2.5 | Diário/handouts com pastas, pins e compartilhamento seletivo | Notas privadas ou para todos, filtro por cena, versão de edição e compartilhamento de capítulos existem. Sem pins no mapa, anexos no diário ou acesso por jogador. | Pastas, pin de nota e mostrar handout ao grupo. Médio; reutilizar permissões, evitar outro sistema de notas. | Textos, mapas e referências de aventuras. |
| P2.6 | Encontros, tabelas roláveis e tesouro | Combate com iniciativa/turnos existe; faltam orçamento de encontro, geradores e distribuição de saque. | Tabelas e adicionar encontro ao combate, com edição antes de aplicar. Médio; cálculos 2014/2024 precisam ficar separados. | `encounters.json`, `loot.json`, bestiário e tabelas. |
| P2.7 | Áudio e comunicação de sessão | Uma URL de ambiente com volume/loop; chat de cena limitado a 200 mensagens. Sem playlists, crossfade, sussurros ou rolagens privadas. | Rolagens privadas e playlists antes de voz/vídeo. Médio; áudio integrado custa armazenamento e compatibilidade de autoplay. | Não fornece infraestrutura de comunicação. |
| P2.8 | Atualização/reversão do acervo e backup da campanha | Importação por upsert; não retira registros removidos upstream. Houve inclusão de overrides Foundry como magias, mitigada no importador/consulta. Exporta ficha, não pacote completo de campanha. | Manifesto de versão, diff de atualização, desativação de obsoletos e backup/restauração da mesa. Médio/alto; mais armazenamento, menos perda de personalizações. | Fonte e IDs ajudam; ciclo de atualização é nosso. |
| P3 | Cartas, bastiões, veículos, extensões, voz/vídeo e efeitos avançados | Não há subsistemas dedicados. GIF em ação já existe. | Integrar por demanda e opt-in. Alto esforço agregado; abrangência prematura congestiona a interface e aumenta manutenção. | Dados de várias categorias existem; voz/vídeo/plugins são outra infraestrutura. |

## O que já temos e deve ser reaproveitado

- Conta, biblioteca de mesas, vínculo de campanha e papéis mestre/jogador.
- Múltiplas cenas, fundo, grade, tokens vinculados à ficha, paredes, portas, luzes e névoa básica.
- Fichas rápidas/clássicas, aba separada, combate/iniciativa e dados no chat.
- Compêndio 2014/2024, escolha de fontes, importação de criaturas/itens/magias e compartilhamento de capítulos.
- Notas privadas/públicas por cena, edição com conflito detectado e regras da casa por campanha.
- Referências de ilustrações e tokens com cache sob demanda. Referência cadastrada não significa que toda imagem remota foi testada ou baixada.

## Acervo 5etools: o que integrar e o custo real

Contagens abaixo são do clone auditado; não significam entradas inéditas, todas de 2024 ou todas mecanicamente compatíveis.

| Ordem | Fonte upstream | Aproveitamento proposto | Trabalho e limite |
|---|---|---|---|
| 1 | `data/generated/bookref-quick.json`; actions/senses/skills/conditionsdiseases | Referência rápida por contexto: turno, ação, condição, sentido. | Baixo/médio; ações/condições já estão em Regras, falta experiência de consulta e links. |
| 2 | `data/spells`, `data/class`, `data/races.json`, backgrounds/feats | Magias acionáveis e completar wizard. | Alto; dados não implementam efeitos nem validam escolhas sozinhos. |
| 3 | `data/bestiary/legendarygroups.json`, `data/magicvariants.json` | Ações de covil/efeitos regionais e variantes reais de itens. | Médio; resolver referências/herança antes de instanciar, sem duplicar conteúdo base. |
| 4 | `data/book`, `data/adventure`, arquivos fluff | Mapas/handouts, navegação e importação guiada de capítulos. | Alto; a leitura já existe parcialmente. Escala, paredes, localização de encontros e spoilers precisam de revisão. |
| 5 | `data/loot.json`, `data/encounters.json` (42 grupos) | Tabelas roláveis, tesouro e encontros. | Médio; persistir resultado e aplicar ao inventário/combate mediante escolha. |
| 6 | `data/trapshazards.json` (37 armadilhas, 73 perigos), `data/objects.json` (37 objetos) | Preparação de cena com perigos e objetos acionáveis. | Médio; referência primeiro, efeitos depois. |
| 7 | `data/languages.json` (201 idiomas), `data/deities.json` (563 divindades), `data/rewards.json` (278 recompensas) | História, proficiências e recompensas vinculadas à ficha. | Baixo para consulta, médio para ficha; muitas fontes, manter opt-in. |
| 8 | `data/bastions.json` (70 instalações), `data/vehicles.json` (39 veículos, 31 melhorias) | Subsistemas opcionais de campanha. | Alto; requer turnos, recursos e interfaces próprias. |
| 9 | `data/decks.json` (35 baralhos, 765 cartas), `data/recipes.json` (241 receitas), `data/psionics.json` (52), `data/cultsboons.json` (30 cultos, 12 dádivas) | Pacotes especializados escolhidos pelo mestre. | Médio/alto; baixo retorno para o núcleo do combate. |

Fonte primária do acervo: [5etools, revisão auditada](https://github.com/5etools-mirror-3/5etools-src/tree/e5d052071b635f58cc8006e9727053eaf78ea8f9/data). Dados de livros/imagens não devem ser tratados como licenciados automaticamente pela licença do código; confirmar permissões de distribuição antes de publicar um pacote de conteúdo.

## Evidências locais e limites

| Constatação | Evidência |
|---|---|
| Ataque ou dano; chat com 200 mensagens | `apps/api/app/Http/Controllers/Api/SceneController.php`, métodos `action` e `chat`. |
| Estado completo e lista de cenas | `apps/api/app/Events/SceneUpdated.php::broadcastWith`; `CampaignSceneController::payload`; `SceneController::show`. |
| Fichas visíveis a membros; JSON pouco validado | `apps/api/app/Http/Controllers/Api/ActorController.php::index/show/update`. |
| Fontes globais de revelação e raio fixo | `apps/web/src/pixi/VttTable.ts::drawFogAndLight`; `apps/web/src/lib/visibility.ts`. |
| Wizard de nível 1, escolhas incompletas | `apps/web/src/components/CharacterWizard.tsx`; `apps/web/src/lib/characterBuilder.ts`. |
| Referências/imagens de livros ainda limitadas | `apps/web/src/components/FiveToolsEntries.tsx`; `scripts/import-reading.py`. |
| Importação e mídia | `scripts/build-compendium.py`, `resolve-five-tools.mjs`, `enrich-catalog-images.mjs`; `ImportFiveToolsCommand.php`; `CatalogMediaController.php`. |
| Biblioteca/compartilhamento/notas existentes | `CatalogController.php`; `JournalController.php`; `CampaignLibrary.tsx`; `JournalPanel.tsx`. |

Esta rodada foi de inspeção e documentação: não repetiu a suíte completa nem fez benchmark/mobile. Ausência significa que não foi encontrada implementação dedicada nos fluxos, rotas e tipos auditados. As conclusões sobre concorrência e exposição de payload são derivadas do código, não de exploração contra uma mesa de terceiros.

## Sequência de entrega sem sobrecarregar a interface

1. Fechar P0 e garantir recuperação de dados.
2. Completar uma rodada de combate: escolher ação → alvo/área → rolar → aplicar resultado → desfazer.
3. Completar criação de ficha e validar essa rodada em tablet/celular.
4. Importação guiada de aventura e consulta contextual.
5. Pacotes opcionais de homebrew, tabelas e subsistemas especializados.

Preparação concentra fontes, importações, permissões e mapas. Durante o jogo ficam mapa, ações da ficha, combate, conversa e consulta contextual. Novas categorias não ganham abas permanentes por padrão.
