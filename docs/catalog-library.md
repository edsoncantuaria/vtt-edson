# Biblioteca única e proveniência — issue #4

O acervo de consulta é global; as fontes habilitadas pela campanha restringem a pesquisa. A escolha de uma edição no filtro serve para leitura/comparação, mas **somente a edição configurada na campanha pode ser materializada** em fichas ou ferramentas. A UI usa três visões sem misturá-las:

| Visão | Significado | Critério técnico |
| --- | --- | --- |
| Biblioteca | Verbetes disponíveis e autorizados, com filtros por nome, categoria, edição, fonte e nível. | `GET /catalog/all?campaignId=...&scope=library`; categorias: monstros, magias, itens, classes, raças/espécies, backgrounds, feats e aventuras. Categorias adicionais continuam acessíveis pelo seletor. |
| Na campanha | Entradas explicitamente materializadas em fichas, criadas como monstros, referenciadas na progressão, importadas como cena ou compartilhadas. Não é sinônimo de fonte habilitada. | `scope=campaign`: deriva vínculos de `actor_documents.catalog_entry_id`, `actors.system.origin/preparation/progression`, `scenes.state.preparation.entryId` e `campaign_catalog_shares`. |
| Homebrew | Conteúdo versionado de pacotes habilitados e pertencentes àquela campanha. | `scope=homebrew`, com IDs/versões próprios e sem fingir que uma entrada caseira é oficial. Pacotes desabilitados permanecem fora da consulta. |

`GET /catalog/{kind}` permanece compatível com consumidores atuais: sem `scope` continua entregando verbetes oficiais + homebrew separado. `all` exige `campaignId`. O backend filtra fonte/edição, entradas ativas e acesso a livros/aventuras privados **antes** de paginar: jogadores só visualizam o Livro do Jogador acessível e capítulos compartilhados; o GM pode administrar compartilhamentos. Fonte nomeada vem de `data.sourceName` junto de seu código estável (`source`) em `/campaigns/{id}/catalog-sources`. Quando o registro não contém nome estendido, a UI mostra apenas o código disponível, sem inventar um título.

Na importação oficial de item/magia/feature, `actor_documents.catalog_entry_id`, `slug`, `source`, `edition`, nome da fonte e `content_hash` são preservados como snapshot em `document.data.origin`; uma atualização posterior do acervo não sobrescreve o documento ou seus overrides. Para uma cópia homebrew, a UI guarda ID da entrada, pacote, versão e referência em `data.origin`. Monstros materializados preservam origem em `actor.system.origin`, enquanto instâncias distintas de um encontro continuam atores distintos. O conteúdo adquirido mantém a versão/materialização de então até uma ação de atualização explícita de outra issue.

Quando a mídia falhar ou não existir, o detalhe mostra aviso legível e mantém dados e referências. Citações/créditos presentes no verbete original são mantidos no detalhe; a UI não oculta obrigações de licença nem usa siglas de provedores externos como instrução de jogo. O recurso de importação de aventuras permanece separado da mera consulta e conserva a etapa de revisão do mapa.

Validação: `CatalogTest`, `CatalogImportLifecycleTest`, `AdvancedVttDomainsTest`, `HomebrewContentDomainTest`, testes de apresentação do compêndio e build web. O aceite de multiplayer presencial e de todo o golden path é um gate posterior; a documentação não substitui essa demonstração.
