# Referência de QA — O Farol das Marés

[Índice](README.md) · [Roteiro de demonstração](demo-checklist.md)

Miniaventura original, de uma sessão longa ou duas curtas, sem dependência de texto, nomes de monstros protegidos, imagens ou mapas de terceiros. A fixture **ainda deve ser implementada** na issue [#52](https://github.com/edsoncantuaria/vtt-edson/issues/52); este é seu contrato de conteúdo e resultado, não um seed já presente.

**Premissa:** quatro aventureiros chegam a um farol desativado. Um guardião ferido avisa que a luz foi apagada por criaturas que protegem um artefato. O GM começa com o saguão preparado e mantém a câmara subterrânea privada até chegar a hora.

| Caso | Cena, ação e resultado esperado | Exercício técnico |
| --- | --- | --- |
| R01 | Criar campanha 5e, selecionar uma edição e fontes permitidas, importar/criar a aventura original e abrir sua primeira cena. | G01–G05; proveniência e edição |
| R02 | Convidar quatro jogadores, criar personagens, validar escolhas, aprovar fichas e colocar tokens individuais. | G06–G09; papéis/isolamento |
| R03 | Explorar saguão parcialmente escuro; atravessar porta que revela nova área e uma nota afixada. | G10–G11; visão, portas, fog e handout |
| R04 | Investigar mecanismo e fazer Percepção/Investigação; falha não bloqueia a aventura, apenas introduz custo/consequência narrativa. | G12; skill roll e GM override |
| R05 | Conversar com guardião, usar whisper do GM e mostrar aos jogadores apenas o handout público. | G11/G22; segredo REST e websocket |
| R06 | Acionar armadilha no corredor; múltiplos alvos fazem DEX save com efeitos de sucesso/falha. | G13/G17; seleção, save e aplicação |
| R07 | Iniciar combate contra duas instâncias da mesma criatura original com HP independente; rolar iniciativa. | G09/G13; actor versus token |
| R08 | Criatura ataca guerreiro; rolar acerto, dano, resistência se aplicável, HP temporário e condição com duração. | G14–G16; pipeline auditável |
| R09 | Conjurador usa cura e magia de área, escolhe recurso/slot e alvos; GM revisa saves e aplica efeitos. | G15/G17/G18; custo e confirmação |
| R10 | Encerrar combate, executar descanso curto e depois um longo quando permitido; visualizar recuperação antes de confirmar. | G19–G20; regras de recuperação |
| R11 | Abrir baú, distribuir itens/moedas, equipar um item, encerrar sessão e simular retorno no dia seguinte com reload/websocket interrompido. | G21–G24; inventário/continuidade |
| R12 | GM concede milestone, jogadores resolvem level-up e avançam para o topo do farol; GM conclui aventura preservando personagens/histórico. | G25–G27; progressão e fechamento |

**Estado inicial reproduzível:** um GM e quatro players distintos; duas cenas (saguão preparado/publicado e câmara não publicada); quatro fichas válidas; pelo menos dois inimigos gerados da mesma referência; um handout público e outro secreto; objeto interativo, armadilha, tesouro e milestone. Não depender de IDs fixos em roteiro de usuário: selecionar sempre por nome/representação visual. Forçar uma falha de rede após rolagem/dano e validar que o retorno não reaplica efeito. Registrar o tempo e número de cliques para encontrar magia, adicionar criatura, atacar e trocar cena.

**Aceite da fixture:** R01–R12 são executados por UI com resultado esperado documentado e nenhuma propriedade privada é entregue a um player indevido. A fixture alimenta E2E e playtests, não substitui testes de regra isolados.
