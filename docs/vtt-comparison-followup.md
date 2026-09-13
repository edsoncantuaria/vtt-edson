# Comparação complementar: Fantasy Grounds e Shard

Consulta: 13/09/2026. Complementa `vtt-audit-priorities.md`. Escopo: documentação pública oficial; nenhum produto foi testado em sessão. “Documentado” significa descrito pelo fabricante, não uma garantia de funcionamento ou de disponibilidade em todo plano/conteúdo.

## Prioridades para este VTT

O estado local abaixo corresponde à etapa de implementação em andamento, informada pela integração principal: compartilhamento de leitura de fichas, remoção de tokens ocultos do payload de jogador, rolagem de ataque e dano, catálogo P3 opcional, importação de mapas de capítulos como cenas privadas com notas/grid e escolhas de equipamento/magias no wizard. A conclusão depende dos testes dessa integração; não é uma nova auditoria independente do código.

| Prioridade | Referência verificada na documentação | Diferença local / melhoria recomendada | Trade-off | Aproveitamento do 5etools |
|---|---|---|---|---|
| P0 | Fantasy Grounds anuncia automação de ataques, salvaguardas, dano, condições e efeitos [1]. | Rolar ataque e dano é apenas parte do fluxo. Próximo passo: alvos, confirmação, resistência/imunidade, concentração, consumo de recurso e desfazer com histórico. | Regras de 2014/2024 e exceções exigem testes; automação deve permitir decisão do mestre. | Fórmulas, tipos de dano, CD, descrições e condições ajudam; dados não equivalem a um motor de execução. |
| P1 | Shard documenta pendências de criação/evolução, níveis de várias classes, point buy e seleção de equipamento ou ouro [3]. | Wizard precisa validar escolhas e pré-requisitos por nível/edição, além de oferecer a lista filtrada. Manter pendências visíveis e retomáveis. | Multiclasse e escolhas condicionais aumentam bastante a modelagem. Entregar por regras explicitamente suportadas. | Classes, progressões, talentos e equipamentos podem alimentar as escolhas; normalização e validação pertencem ao VTT. |
| P1 | Shard documenta filtro de magias por classe, ritual, escola e nível; distingue conhecidas e preparadas e permite ignorar a lista da classe [3]. | Diferenciar conhecida/preparada, quantidade permitida e exceção aprovada pela mesa; mostrar por que uma escolha não é elegível. | Flexibilidade de homebrew precisa preservar a explicação dos cálculos. | Metadados de magias/classes; fontes habilitadas continuam sendo limite da campanha. |
| P1 | Em aventuras publicadas do Shard, livro abre mapa, compartilha texto/arte, inicia encontro e conecta pins; mapas, monstros e tesouro são previamente preparados [4]. | Importar a imagem como cena privada não prepara paredes, portas, encontros ou posicionamento. Oferecer revisão de escala e links livro→cena→nota antes de publicar. | Preparação automática incompleta pode revelar segredos ou montar encontros errados; revisão humana é necessária quando os dados não existem. | Capítulos, mapas, referências e arte são base. Não presumir coordenadas de tokens/paredes nos livros. |
| P1 | Fantasy Grounds lista visão dinâmica, iluminação e visão no escuro como recursos centrais [1]. | Validar visão/permissão ponta a ponta e experiência de edição de paredes/portas antes de efeitos mais sofisticados. | Custo de geometria e sincronização, especialmente em tablet; estado oculto precisa permanecer protegido na API. | Sentidos/visão das criaturas podem preencher valores, mas geometria do mapa é trabalho do VTT. |
| P2 | Shard descreve ficha com ações, bônus, reações, efeitos ativos e condições; anuncia automação de características como Sneak Attack [2]. | Agrupar ações disponíveis no turno e explicar efeitos aplicados. P3 deve aparecer apenas quando habilitado. | Mostrar todas as opções de uma vez piora a jogabilidade; modelar subsistemas gradualmente. | Catálogo opcional fornece referências; bastiões, veículos e outros dados não ganham automação só por serem importados. |
| P2 | Shard permite montar token com borda/fundo e redimensiona conforme o tamanho do personagem [3]. | Complementar imagens remotas com recorte, borda e tamanho revisável; manter fallback e vínculo com ficha. | Armazenamento e processamento de mídia; URLs externas podem desaparecer. | Tokens/retratos disponíveis são reutilizáveis tecnicamente, conforme disponibilidade e permissão do acervo. |
| P2 | Fantasy Grounds documenta criação/exportação de módulos próprios [1]; Shard permite modificar a aventura publicada [4]. | Separar atualização do catálogo da cópia editada na campanha, com comparação de mudanças e exportação/backup. | Controle de versões é mais trabalho, mas evita sobrescrever preparação do mestre. | IDs e fontes do upstream ajudam a rastrear origem; política de mesclagem continua sendo responsabilidade local. |

## Distinções importantes

- **Fantasy Grounds:** [1] é página comercial oficial, não teste de profundidade de cada automação. Ela separa plataforma/regras gratuitas de conteúdo oficial adquirido e de extensões da comunidade via Forge. Não atribuímos funções de extensões ao núcleo.
- **Shard:** [3] e [4] são tutoriais operacionais mais específicos. Aventuras prontas descritas em [4] são pacotes preparados pelos autores, gratuitos ou compráveis; isso não demonstra importação automática de qualquer livro do 5etools. [2] contém anúncios do fabricante, sem verificação prática de cobertura.
- **5etools:** acesso técnico a JSON/imagens não estabelece licença de redistribuição de todos os livros e artes. Preservar fonte, revisão e proveniência; não anunciar “100% automatizado” enquanto existirem entradas apenas consultáveis.
- **Mobile:** Shard anuncia funcionamento em qualquer dispositivo [2]. Isso não substitui testes locais de toque, foco, legibilidade, reconexão e mapas grandes no nosso VTT.

## Fontes lidas

1. Fantasy Grounds — página oficial, seções “Features”, “Creation & Customization”, “Free Rulesets & Data” e FAQ: https://www.fantasygrounds.com/home/home.php
2. Shard — página oficial e resumos de atualizações (“Shard Powers Activate”, entre outros): https://www.shardtabletop.com/
3. Shard — tutorial “Character Creation”: https://www.shardtabletop.com/howto/character-creation1
4. Shard — tutorial “Published Adventures”: https://www.shardtabletop.com/howto/published-adventures

Limites da consulta: a URL tentada do Roll20 (`https://pages.roll20.net/compendium`) respondeu 404 e não sustentou nenhuma conclusão. Uma URL antiga da wiki do Fantasy Grounds abriu um anexo sem a documentação esperada e foi descartada. Não foram inferidas capacidades de Roll20/Owlbear a partir dessas falhas.
