# Compêndio, fichas e regras da casa

O catálogo local usa o clone `.scratch/5etools-src`, com fontes e versões separadas. A resolução de heranças utiliza o DataUtil do próprio projeto upstream. O texto permanece no idioma original; o catálogo não equivale a automação de todas as regras.

Para reconstruir a carga:

```sh
node scripts/resolve-five-tools.mjs .scratch/5etools-src .scratch/5etools-resolved
python3 scripts/build-compendium.py .scratch/5etools-resolved .scratch/catalog.ndjson
cd apps/api
php artisan migrate
php artisan compendium:import ../../.scratch/catalog.ndjson
```

O relatório `.scratch/catalog.report.json` registra contagens e exclusões. A carga atual contém 12.363 entradas, incluindo ações, condições, sentidos e perícias como referências em Regras. O importador preserva os dados originais; a identificação de edição usa a indicação explícita quando existe e a publicação da fonte nos demais casos.

A ficha oferece modo de jogo e visualização clássica editável, além de abertura em outra aba. O assistente cria personagens de nível 1 e aplica atributos, bônus de origem suportados, PV, salvaguardas, perícias e características. Equipamentos, magias e escolhas específicas devem ser revisados após a criação. Não é uma reprodução gráfica dos PDFs oficiais nem um construtor completo de todos os níveis.

Em Preparar cena → Regras da casa, o mestre salva até 12 regras por campanha. Cada uma procura um texto no nome do teste ou ação, soma um modificador e pode substituir a fórmula. Modificadores acumulam; a última substituição prevalece. O servidor aplica o resultado e registra os nomes das regras no chat. Testes por `/roll` sem rótulo não têm identificação de perícia. Não há execução de scripts arbitrários.

Condições são registradas na ficha e consultadas no catálogo. Ainda não aplicam automaticamente todos os efeitos das regras. Homebrew em fichas é possível por edição de ações, magias, itens e características; um catálogo homebrew compartilhado por campanha e controle de habilitação ainda não estão implementados.

## Livros e aventuras

A carga complementar preserva 1.187 capítulos em `data.raw`, com listas, tabelas e seções renderizadas pelo leitor React. Execute da raiz:

```sh
python3 scripts/import-reading.py .scratch/5etools-src .scratch/reading.ndjson
php apps/api/artisan compendium:import .scratch/reading.ndjson
```

Repetir a importação atualiza entradas por identificador estável; não remove entradas que desapareceram upstream. Leitura exige participação na campanha. PHB e XPHB ficam disponíveis aos jogadores; outros livros e aventuras exigem compartilhamento do capítulo pelo mestre. O mestre pode revogar esse compartilhamento. A classificação por data de publicação é uma aproximação, não uma garantia de compatibilidade de regras.

O clone de código não inclui o acervo completo de imagens. O leitor sinaliza ilustrações ausentes. Download de assets, mapas prontos com paredes/encontros e tokens ilustrados ainda não estão implementados. Referências internas de stat blocks são exibidas por nome, sem navegação automática. Monstros novos preservam o stat block original; fichas antigas não são migradas automaticamente.

## Preparação da campanha

Novas mesas começam com PHB/DMG/MM ou XPHB/XDMG/XMM, conforme a edição. É possível escolher todo o acervo na criação ou ajustar fontes em Preparar cena → Biblioteca da campanha. O filtro é aplicado às consultas do compêndio e do assistente de fichas; não remove conteúdo de fichas já criadas. Mesas anteriores preservam o acervo completo até o mestre selecionar fontes. Capítulos compartilhados são exceções explícitas à seleção de fontes.

O diário filtra notas da cena atual, privadas ou compartilhadas. Novas notas são privadas e vinculadas à cena. Publicação requer salvar a visibilidade Grupo. Edições com versão desatualizada são rejeitadas com 409 para preservar mudanças feitas em outra aba.

Paredes, portas e luzes podem ser removidas individualmente na preparação, com permissão do mestre. O retrato enviado na ficha é reutilizado por seus tokens em todas as cenas. JPG/PNG/WebP até 5 MB e 4096 px; falha de imagem mantém as iniciais no token. Isso não constitui uma importação do acervo ilustrado completo do 5etools.

## Imagens de magias e tokens do 5etools

Após gerar o catálogo, associe as referências oficiais de mídia antes de importar:

```sh
node scripts/enrich-catalog-images.mjs .scratch/5etools-src .scratch/catalog.ndjson .scratch/catalog-images.ndjson
php apps/api/artisan compendium:import .scratch/catalog-images.ndjson
```

A carga verificada associa 4.530 tokens e 3.241 verbetes com ilustrações. O clone possui 91 registros de ilustração de magias; não oferece uma imagem exclusiva para cada magia. A resolução dos nomes de tokens usa o Renderer do próprio 5etools. A arte e seu crédito aparecem nos detalhes do compêndio; listas usam miniaturas e mantêm o ícone quando a imagem falha.

O endpoint público de mídia entrega apenas imagens públicas referenciadas pelo catálogo no domínio 5e.tools, sem redirecionamentos, e valida formato e limite de 5 MB. O servidor armazena cache sob demanda para evitar CORS no mapa e downloads repetidos. É necessário acesso à internet na primeira carga. Não houve download integral ou verificação individual de todas as imagens remotas. Novos monstros importados recebem a referência do token; retratos enviados manualmente têm prioridade. Fichas já criadas não são alteradas automaticamente.

## Cenas em preparação e gravação simultânea

Novas cenas começam privadas. Em Preparar cena, o mestre publica para o grupo ou retorna à preparação; deve manter pelo menos uma publicada. Cenas existentes na migração preservam acesso. A API e a autorização do canal bloqueiam leitura/ações de jogadores em cenas privadas. Eventos de cena e combate carregam apenas identificadores e o cliente busca o estado autorizado. Fichas e tokens dentro de cenas publicadas ainda precisam da separação de leitura prevista na auditoria.

Mutações vinculadas à cena usam transação e recarregam a cena sob lock antes de chamar o controller; broadcasts aguardam commit. O teste cobre recarga de binding desatualizado e rollback, não substitui teste concorrente em PostgreSQL. A edição de fichas ainda precisa de revisão/schema no servidor.

## Entrega de 2026-09-13: permissões, ações e importação

Fichas passam a ser privadas ao dono e mestre por padrão. O mestre pode compartilhar leitura com o grupo. Jogadores não podem consultar/exportar fichas privadas de outros. A presença de um token não concede leitura do stat block; tokens explicitamente ocultos são filtrados nas respostas da cena, entrada da sala e combate. Isso não implementa ainda filtragem automática de tokens por linha de visão no servidor.

Ações rolam ataque e dano separadamente na mesma execução, preservando ambos no chat. Ainda não aplicam automaticamente acerto, crítico, resistências, salvaguardas, concentração ou consumo de recursos. Regras da casa existentes são aplicadas às fórmulas; confira o alcance delas para não alterar ataque e dano por engano.

No wizard de nível 1 há filtro por fonte da campanha e seleção de equipamento/magias antes da criação. Quantidades, orçamento, limites de magias, multiclasse, evolução e todas as escolhas de origem/classe ainda não são validados integralmente.

Na categoria Aventuras, o mestre abre um capítulo e escolhe Preparar mapas. Até dez mapas por chamada viram cenas privadas, com notas do capítulo; grades quadradas são aproveitadas quando fornecidas. Mapas de jogador são pré-selecionados. Mapas hexagonais não são convertidos. Reimportar o mesmo capítulo/mapa preserva a cena existente. Paredes, portas, encontros e posicionamento de criaturas não são inferidos dos polígonos de regiões e ainda precisam de preparação manual. Créditos e origem seguem no verbete original. A imagem é servida pelo cache de mídia com limite de 15 MB para mapas; mapas maiores podem não carregar. Retratos e ilustrações continuam limitados a 5 MB.

### Referências opcionais P3

```sh
node scripts/import-optional.mjs .scratch/5etools-src .scratch/optional.ndjson
php apps/api/artisan compendium:import .scratch/optional.ndjson
```

Foram importadas 2.464 entradas opcionais em bastiões, veículos/melhorias, baralhos/cartas, receitas, psiônicos, recompensas, divindades, idiomas, armadilhas/perigos, objetos e cultos/dádivas. São referências adicionáveis à ficha, não subsistemas automatizados. A opção Conteúdos opcionais as revela sem criar novas abas permanentes; a seleção de fontes da campanha permanece em vigor.
