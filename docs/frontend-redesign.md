# Reconstrução da experiência de jogo

O frontend organiza a experiência em conta, biblioteca de mesas e sessão. O backend Laravel continua responsável por autenticação, permissões, persistência, rolagens e transmissão das alterações.

## Fluxos

- Entrar ou criar uma conta; criar uma mesa ou participar usando código.
- Consultar as mesas vinculadas à conta, filtrando por papel e nome.
- Selecionar D&D 5e 2014 ou regras revisadas de 2024 ao criar a campanha.
- Usar mapa central, ferramentas rotuladas e painéis de conversa, fichas, combate, compêndio e diário.
- O mestre configura mapa, grade, tokens, paredes, portas, luzes e áreas reveladas.
- O jogador acessa suas fichas, move seus tokens e participa das rolagens e conversa.
- A ficha separa consulta durante o jogo da edição, com salvamento explícito e confirmação ao descartar alterações.

O compêndio local parte do SRD aberto de 2014, com importação Open5e disponível no backend para ampliar magias, itens e criaturas sem tornar a mesa dependente da rede. A edição de 2024 é identificada na campanha, mas não representa uma implementação completa das regras, conteúdo e automações de 2024. O mestre pode combinar nevoeiro manual com iluminação dinâmica: paredes e portas fechadas recortam a linha de visão dos jogadores.

## Persistência e sincronização

`GET /api/rooms` devolve apenas salas das quais a conta participa. A migração `2026_09_11_000002_add_ruleset_to_campaigns.php` acrescenta a edição e mantém campanhas existentes em 2014.

Alterações locais da cena são serializadas. A resposta HTTP atualiza a tela mesmo sem WebSocket. Reverb transmite alterações; consultas periódicas reconciliam cena, fichas e combate. Respostas de consultas anteriores a uma edição ou evento não substituem o estado mais recente. Respostas de alterações de outra conta ou cena são ignoradas.

Cada campanha pode ter várias cenas, compartilhando seus participantes. O diário persiste por campanha e pode ser público ao grupo ou visível só ao mestre. Áudio ambiente é configurado pelo mestre por URL HTTPS, mas só começa após o clique de cada participante, como exigem os navegadores. Fichas podem abrir em uma aba própria com o contexto da mesa preservado.

## Executar e verificar

Aplicar as migrações do Laravel antes de abrir a nova interface. O frontend usa `pnpm dev:web`; verificações: `pnpm build:web`, `pnpm --filter @vtt/web lint` e `pnpm test:web`. Testes PHP ficam em `apps/api` e usam `php artisan test`.

O proxy Vite encaminha `/api` e `/storage` ao backend. URLs de armazenamento são normalizadas para a mesma origem para carregamento pelo WebGL. O link `public/storage` precisa apontar para `storage/app/public`.

O arquivo `apps/api/docker/uploads.ini` amplia os limites do PHP para o upload de mapas de até 10 MB. Reconstruir a imagem Docker para aplicar essa configuração. Em execução nativa, incluir esse diretório no `PHP_INI_SCAN_DIR`, preservando os diretórios padrão.

## Referências visuais

- [Foundry: orientação de jogadores](https://foundryvtt.com/article/player-orientation/): mapa central, ferramentas de cena e painéis de sessão.
- [Roll20](https://roll20.net/): campanhas, convite, fichas e dados integrados.

As imagens desta continuação foram geradas no [Gemini](https://gemini.google.com/?hl=pt-BR), pelo navegador, por solicitação do usuário. Não foram copiadas imagens de Foundry ou Roll20.

`watchtower-map-gemini.jpeg`: mapa ortográfico de pátio de uma torre em ruínas na floresta, fonte central, acessos largos, área de circulação livre, pedra e verde musgo, sem tokens, texto ou grade. Disponível para download no painel Cena.

`adventure-citadel-gemini.jpeg`: cidadela entre montanhas e pinheiros ao anoitecer, janelas âmbar, paleta de ardósia e verde, formato 16:9, foco à direita e sem texto. Uma revisão pelo próprio Gemini removeu uma faixa artificial da primeira geração. Usada na entrada, biblioteca e capas de mesas sem mapa.

As imagens `adventure-citadel.png` e `watchtower-map.png` foram produzidas na etapa anterior com ImageGen. A primeira usou uma cidadela entre montanhas e névoa, luz âmbar e espaço para título; a segunda, um pátio ortográfico com torre em ruínas e fonte. Permanecem identificadas separadamente dos arquivos gerados no Gemini.
