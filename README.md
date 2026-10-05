# EBR Ads

Gerenciador de anúncios para WordPress: inserção automática por posição, shortcode `[ebr_ad id="1"]`, widget e condições de exibição. Substituto direto do Quick AdSense Reloaded (WP QUADS), cuja configuração é importada na ativação.

Detalhes de funcionamento, permissões e FAQ: [readme.txt](readme.txt).

## Atualizações

A versão vem dos **tags** deste repositório (`v1.2.1`, `v1.2.2`...). Quando existe um tag maior que a versão instalada, o WordPress mostra o aviso clássico de nova versão em **Plugins**, com "Atualizar agora". Para atualizar sozinho, clique em **Ativar atualizações automáticas** na linha do EBR Ads.

Os sites consultam o GitHub a cada 6 horas. Para ver na hora: **Painel → Atualizações → Verificar novamente**.

## Publicar uma versão

1. Em `ebr-ads.php`, altere `Version:` e `EBR_ADS_VERSION` para a nova versão. Em `readme.txt`, `Stable tag` e o Changelog.
2. Commit, tag e push:

```sh
git commit -am "Versão 1.2.2"
git tag v1.2.2
git push origin main --tags
```

O tag precisa ser igual à versão do arquivo. Se o tag for maior, o site instala e continua vendo "nova versão" para sempre.

## Instalar num site novo

O zip que o GitHub gera para um tag tem a pasta `ebr-ads-1.2.2`, e o WordPress instalaria com esse nome. Gere o zip com a pasta certa a partir do tag:

```sh
git archive --prefix=ebr-ads/ -o ebr-ads.zip v1.2.2
```

e envie em **Plugins → Adicionar novo → Enviar plugin**. Depois disso as atualizações seguem pelo painel.
