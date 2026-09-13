# Referências externas da auditoria

Consulta: 2026-09-13. Comparação funcional, sem afirmar equivalência de planos, sistemas instalados ou módulos. A lista de prioridades é uma recomendação para nosso produto, não uma lista de funcionalidades universais de todos os concorrentes.

## Confirmado em documentação oficial nesta rodada

| Produto/fonte | Capacidade documentada | Adaptação recomendada |
|---|---|---|
| [Foundry — Journal Entries](https://foundryvtt.com/article/journal/) | Páginas de texto/imagem/vídeo/PDF; mostrar a todos ou jogadores específicos; permissões; pins de mapa; links dinâmicos; texto secreto. | Diário com links e handouts, publicação seletiva e pins. Não precisamos copiar todo o editor de uma vez. |
| [Foundry — Measurement and Templates](https://foundryvtt.com/article/measurement/) | Régua, medição ao arrastar token, waypoints e templates de círculo/cone/retângulo/raio. | Régua e áreas de magia antes de animações. |
| [Foundry — Walls](https://foundryvtt.com/article/walls/) | Barreiras configuráveis para visão/movimento/luz/som, portas normais e secretas, janelas e edição de paredes. | Separar visão e movimento; portas secretas/trancadas só após acertar permissões. |
| [Foundry — Scenes](https://foundryvtt.com/article/scenes/) | Cena ativa compartilhada distinta da cena visualizada individualmente; diretório, pastas e criação de cenas. | Mestre prepara sem mudar a tela do grupo; publicação/ativação explícita. |

Essas quatro páginas retornaram HTTP 200 e seu texto foi consultado. Elas fundamentam as comparações específicas sobre diário, geometria e cenas.

## Roll20 e Owlbear: limitações da conferência

As tentativas em [Roll20](https://roll20.net/), [Character Sheets](https://help.roll20.net/hc/en-us/articles/360037773573-Character-Sheets), [Owlbear Rodeo](https://www.owlbear.rodeo/) e [documentação Owlbear](https://docs.owlbear.rodeo/docs/getting-started/) retornaram HTTP 403. O navegador in-app estava indisponível. Portanto não foram confirmados nesta rodada preços, disponibilidade por plano nem estado atual dessas funcionalidades.

Como referências de produto conhecidas, ainda sujeitas a revalidação oficial: Roll20 tem fichas/rolagens, ferramentas de compêndio/construção de personagens, handouts, macros e iluminação; Owlbear é referência de foco no mapa e expansão por extensões. Não se atribui aqui ao Roll20 um motor universal de aplicação automática de dano, condições ou concentração: automação depende da ficha, edição, ferramentas e eventuais scripts.

Para nossa plataforma, a recomendação inspirada no Roll20 é reduzir o caminho ficha → rolagem → chat e separar preparo de conteúdo da interação da sessão. A inspiração no Owlbear é manter o mapa central e funcionalidades opcionais fora do caminho principal. Essas são decisões de design propostas, não resultados de benchmark comparativo.

## Limites da auditoria

Não houve sessão prática nos concorrentes, teste de mobile comparativo ou validação de todas as regras D&D. Recursos de Foundry podem variar por versão/sistema e extensões; não são automaticamente recursos nativos de qualquer sistema D&D. O estado local foi inspecionado diretamente no workspace e detalhado em [prioridades](vtt-audit-priorities.md).
