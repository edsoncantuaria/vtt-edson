# B. Reunir o grupo

[Índice](README.md) · [Anterior: preparação](01-preparacao.md) · [Próximo: jogo](03-jogo.md)

| ID | Ator, gatilho e superfície de UI | Estado persistido, transição e autorização | Falha recuperável / recarga |
| --- | --- | --- | --- |
| G06 Entrar na mesa | Player → autenticação/Lobby → Entrar com código. | `CampaignMember` + associação `Room`; `Convidado → Membro/pendente` conforme política de entrada. API `POST /rooms/join`, `RoomController.php`. | Código inválido/expirado não revela campanha privada; recarregar não repete ingresso; usuário retorna à mesa permitida. |
| G07 Criar/importar personagem | Player → Criar personagem → Wizard por edição/fontes ou importar ficha suportada. | `Actor` persistido ligado a campanha/proprietário e escolhas; `Rascunho → Submetido`. `CharacterWizard.tsx`, `ActorController.php`. **Lacuna:** fechar escolhas condicionais e importação fim a fim; nunca validar só em React. | Erro de pré-requisito mostra campo pendente e permite retomar; refresh não perde escolhas confirmadas; NPC não vira PC automaticamente. |
| G08 Aprovar personagem | GM → lista de participantes/personagens → revisar, aprovar ou solicitar ajuste; player acompanha status. | Estado de revisão e concessão explícita de controle/leitura; `Submetido → Aprovado` ou `→ Ajustes solicitados`. `CampaignMemberController.php`, `ActorController.php`. **Lacuna:** confirmar workflow explícito de aprovação da ficha em `main`; vínculo de membro não basta. | Negativa deve mostrar motivo ao dono sem vazar outros personagens; refresh preserva decisão/auditoria; sem aprovação player não controla personagem alheio. |
| G09 Colocar personagens na cena | GM → Biblioteca da campanha/Personagens → arrastar para cena; jogador vê o token autorizado. | `Actor` permanece a identidade; cada entrada cria `Scene.state.tokens[]` com ID de instância, dono/controlador, posição, HP/visão e vínculo com ator; `Aprovado → Em cena`. `SceneTokenController.php`, `VttTable.ts`. | Falha de autorização/posição preserva ator; duplicatas intencionais têm estado independente; reload mantém instância sem revelar token oculto. |

**Gate de saída:** quatro jogadores entram com suas contas, concluem fichas elegíveis, GM aprova e coloca tokens controláveis na primeira cena. Issues correlatas: #3, #5, #23, #41.
