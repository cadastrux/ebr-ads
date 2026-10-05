=== EBR Ads ===
Contributors: ebrnetwork
Tags: ads, adsense, advertising, banner
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gerenciador de anúncios enxuto: inserção automática por posição, shortcode, widget e condições de exibição. Sem tracking e sem serviços externos.

== Description ==

Substituto direto do Quick AdSense Reloaded (WP QUADS) para quem só precisa
posicionar anúncios — sem relatórios, sem venda de espaço, sem integrações de
pagamento.

= O que faz =

* 10 slots de anúncio para conteúdo e 10 para widgets.
* Inserção automática em 10 posições: início, meio e fim do post, após a tag
  `<!--more-->`, antes do último parágrafo, após o parágrafo N (três slots
  independentes), após a imagem N e após um elemento com uma classe CSS
  ("After Class" — vale para a página inteira, inclusive templates de
  categoria).
* Limite de anúncios por página, aplicado a inserção automática, shortcode e
  widget somados.
* Condições de exibição por post type, home, categoria, arquivo, tag, e opção
  de esconder anúncios para usuários logados.
* Shortcode `[ebr_ad id="1"]`, com `[quads_ad]` e `[quads]` como aliases, mais
  um botão "Anúncio" na aba Texto do editor clássico.
* Caixa "EBR Ads - Ocultar anúncios" na tela de edição, com as mesmas opções
  do "WP QUADS - Hide Ads": ocultar todos, só os automáticos, só a sidebar ou
  posições específicas em um conteúdo.
* Dois tipos de anúncio: código bruto (AdSense) e imagem + link estruturada.

= O que deliberadamente NÃO faz =

Cada item abaixo é uma decisão de projeto tomada depois de auditar o QUADS.

* **Não registra impressões nem cliques.** Nenhuma tabela é criada. Não há
  contador que possa ser fraudado nem tabela que possa crescer sem limite.
* **Não expõe nenhum endpoint.** Zero rotas REST, zero handlers AJAX — nem
  públicos, nem autenticados. Toda a administração passa por `admin-post.php`
  com nonce e capability.
* **Não coleta dado nenhum de visitante.** Sem IP, sem user agent, sem
  referrer, sem cookies.
* **Não envia dado a serviço externo.** Sem telemetria, sem licenciamento
  remoto. A única conexão de saída é a consulta, a cada 6 horas, dos tags
  de versão em `api.github.com` (somente leitura, sem token, com User-Agent
  próprio — a URL do site não é enviada).
* **Não escreve arquivos no disco**, inclusive `ads.txt`.
* **Não executa SQL.** Não há uma única chamada a `$wpdb`.

= Modelo de permissões =

Duas capabilities, verificadas de forma independente:

* `ebr_manage_ads` — gerenciar anúncios, posições e visibilidade.
* `ebr_manage_ad_code` — gravar HTML/JavaScript bruto no campo Código.

Quem tem apenas a primeira edita rótulo, alinhamento, margem e posição, mas o
campo de código fica somente leitura e seu conteúdo é preservado ao salvar.
Injetar JavaScript em todas as páginas do site é uma capacidade
qualitativamente diferente de gerenciar campanhas, e por isso é concedida
separadamente.

As duas vão para o administrador na ativação. Para conceder a outra role, use
`add_cap()` — não existe mapa de roles editável pela interface, de propósito:
um mapa gravável é um caminho de escalação de privilégio.

== Installation ==

1. Envie a pasta `ebr-ads` para `/wp-content/plugins/`.
2. Ative pelo menu Plugins.
3. Na ativação, a configuração existente do Quick AdSense Reloaded é importada
   automaticamente. Confira em **EBR Ads → General & Position**.
4. Confirmado o funcionamento, desative o Quick AdSense Reloaded.

== Frequently Asked Questions ==

= A configuração do QUADS é aproveitada? =

Sim. Na ativação o plugin lê `wp_options['quads_settings']` e, se existir, o
custom post type `quads-ads`, e traduz para o formato próprio:

* `ads.ad1..ad10` e `ads.ad1_widget..ad10_widget` → slots de anúncio, com
  rótulo, código, alinhamento e margem;
* `pos1..pos9` → as nove posições, com o anúncio atribuído, o número de
  parágrafo/imagem e a opção de fallback;
* `maxads` → limite por página;
* `visibility` → home, categorias, arquivos, tags, esconder widgets na home,
  esconder para logados;
* `post_types` e `quicktags`.

A leitura é de mão única. Nada é escrito de volta em `quads_settings`, e
desinstalar o EBR Ads não toca em nenhum dado do QUADS.

= O conteúdo já publicado com [quads_ad] continua funcionando? =

Sim. `[quads_ad id="N"]` e `[quads id="N"]` são registrados como aliases,
mas apenas se o QUADS não estiver ativo — durante uma transição com os dois
plugins ligados, o antigo continua respondendo pelos seus próprios shortcodes.

= Posts marcados para não exibir anúncios continuam assim? =

Sim. A post meta do QUADS (`_quads_config_visibility`, com as chaves `NoAds`,
`OffDef`, `OffWidget`, `OffBegin`, `OffMiddle`, `OffEnd`, `OffAfMore` e
`OffBfLastPara`) continua sendo respeitada, junto com as nossas
(`_ebr_ads_disabled` para "ocultar todos" e `_ebr_ads_hide` para as demais).

Para marcar ou desmarcar, use a caixa "EBR Ads - Ocultar anúncios", abaixo do
conteúdo na tela de edição. Ao salvar um post que estava marcado no QUADS, a
marcação passa a ser gravada por este plugin; ao desmarcar, a marcação legada
também é limpa.

Quem pode marcar é quem pode editar aquele post — não é preciso ter
`ebr_manage_ads`, porque é uma decisão editorial sobre o conteúdo.

= Como o plugin é atualizado? =

Pelos tags de https://github.com/cadastrux/ebr-ads. Quando existe um tag
`vX.Y.Z` maior que a versão instalada, o WordPress mostra o aviso clássico de
nova versão em **Plugins**, com "Atualizar agora".

Para publicar uma versão:

1. Em `ebr-ads.php`, altere `Version:` e `EBR_ADS_VERSION` (os dois iguais
   ao tag que será criado). Em `readme.txt`, `Stable tag` e o Changelog.
2. `git commit -am "Versão 1.2.2"`
3. `git tag v1.2.2`
4. `git push origin main --tags`

O tag precisa bater com a versão do arquivo: se o tag for maior, o site
instala e continua vendo "nova versão" para sempre.

= Posso rodar a importação de novo? =

Sim, em **Import/Export → Reimportar do QUADS**. Ela substitui a configuração
atual do EBR Ads e exige `ebr_manage_ad_code`, porque traz código de anúncio.

= Por que não tem relatórios de cliques e impressões? =

Porque um endpoint público de contagem é, ao mesmo tempo, o vetor de fraude de
cliques, a fonte de crescimento ilimitado de tabela e o ponto de coleta de dado
pessoal sem consentimento. Os números do AdSense são a fonte confiável de
qualquer forma — os do plugin nunca batem com os da rede.

== Changelog ==

= 1.2.1 =
* Versionamento só por tags do GitHub (`vX.Y.Z`): sem GitHub Action e sem
  release. O pacote é o zip que o GitHub gera para o tag.
* O modal "Ver detalhes da versão" mostra este Changelog.

= 1.2.0 =
* Nova posição "After Class": insere o anúncio logo depois do elemento com a
  classe CSS informada (ex.: `cat-content`), em qualquer lugar da página.
  Opção de inserir só na primeira ocorrência ou em todas.
* O HTML da página não é reescrito: o anúncio é inserido como texto, sem
  passar por DOMDocument.
* A importação do QUADS traz o anúncio "After Class" do painel novo.

= 1.1.0 =
* Caixa "EBR Ads - Ocultar anúncios" na edição de posts e páginas, com as
  oito opções do "WP QUADS - Hide Ads" (marcações do QUADS são respeitadas).
* Cache de página (LiteSpeed, WP Rocket, W3TC, WP Super Cache) é limpo ao
  salvar as configurações.
* Detecção da home funciona mesmo com templates que usam query_posts().
* Widget em listagens não é mais escondido pela marcação do último post.
* Atualizações automáticas a partir das releases do GitHub.

= 1.0.0 =
* Versão inicial.
* Importação única a partir do Quick AdSense Reloaded.
* Nove posições de inserção automática, shortcode e widget.
* Capabilities separadas para gerenciamento e para código bruto.
