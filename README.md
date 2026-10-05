# EBR Ads

Gerenciador de anúncios para WordPress: inserção automática por posição, shortcode `[ebr_ad id="1"]`, widget e condições de exibição. Substituto direto do Quick AdSense Reloaded (WP QUADS), cuja configuração é importada na ativação.

Detalhes de funcionamento, permissões e FAQ: [readme.txt](readme.txt).

## Instalação

Baixe o `ebr-ads.zip` da [última release](https://github.com/cadastrux/ebr-ads/releases/latest) e envie em **Plugins → Adicionar novo → Enviar plugin**.

Não use o botão "Code → Download ZIP" do GitHub: esse zip tem a pasta com outro nome (`ebr-ads-main`) e as atualizações não funcionam a partir dele.

## Atualizações

Depois de instalado, o plugin consulta as releases deste repositório e o WordPress mostra a versão nova em **Plugins**, como qualquer outro. Para atualizar sozinho, clique em **Ativar atualizações automáticas** na linha do EBR Ads.

## Publicar uma versão

1. Em `ebr-ads.php`, altere `Version:` e `EBR_ADS_VERSION` (os dois iguais).
2. Em `readme.txt`, altere `Stable tag:` e adicione a entrada no `== Changelog ==`.
3. Faça commit e push em `main`.

A Action [release.yml](.github/workflows/release.yml) valida a versão, verifica a sintaxe PHP, gera o `ebr-ads.zip` e publica a release `vX.Y.Z` com as notas do Changelog. Push sem mudança de versão não gera release.

Os sites veem a atualização em até 6 horas, ou na hora, em **Painel → Atualizações → Verificar novamente**.
