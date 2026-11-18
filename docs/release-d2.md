# D2 release distribution

[#23](https://github.com/0xunknownz/webman-starter/issues/23) uses GitHub Release ZIP assets for both packages. After publication, Composer will use https://raw.githubusercontent.com/0xunknownz/webman-starter/composer. It reads `packages.json` from the root of the dedicated `composer` branch and uses Packagist for the other dependencies.

| Package | Composer version | Release tag | Asset |
| --- | --- | --- | --- |
| `z0x/webman-starter` | `1.1.0` | `v1.1.0` | `webman-starter-1.1.0.zip` |
| `z0x/webman-starter-template` | `1.0.0` | `template-v1.0.0` | `webman-starter-template-1.0.0.zip` |

The template tag prefix avoids a collision with the existing base tag `v1.0.0`. The template declares a base dependency of `^1.1.0`.

## Build and check the release candidates

Run from a fresh checkout of the reviewed source revision with PHP 8.1 or later, the PHP ZIP extension, Composer 2, Git, `jq`, and `shasum`. Use the same ZIP bytes for testing and release upload. The base uses Composer's archive builder and the template uses the [template builder](../tools/build-template.php).

```bash
set -eu
stage=$(mktemp -d)
stage=$(cd "$stage" && pwd -P)
base=webman-starter-1.1.0.zip
template=webman-starter-template-1.0.0.zip
composer archive --format=zip --dir="$stage" --file="${base%.zip}" --no-interaction
php tools/build-template.php 1.1.0 "$stage/$template"
php -l tools/build-release-catalog.php
php tools/build-release-catalog.php 1.1.0 "$stage/$base" 1.0.0 "$stage/$template" > "$stage/packages.json"
(
  cd "$stage"
  for asset in "$base" "$template"; do
    shasum -a 256 "$asset" > "$asset.sha256"
    shasum -a 256 -c "$asset.sha256"
  done
)
```

The [catalog builder](../tools/build-release-catalog.php) copies metadata from each ZIP's root Composer manifest and adds the version, HTTPS Release asset URL, and `dist.shasum`. Composer uses SHA-1 for that field. The `.zip.sha256` sidecars record SHA-256 digests for manual checks of the downloads.

```bash
release_url=https://github.com/0xunknownz/webman-starter/releases/download
jq -e \
  --arg base_url "$release_url/v1.1.0/$base" \
  --arg template_url "$release_url/template-v1.0.0/$template" \
  --arg base_sha1 "$(shasum "$stage/$base" | cut -d ' ' -f 1)" \
  --arg template_sha1 "$(shasum "$stage/$template" | cut -d ' ' -f 1)" \
  '.packages["z0x/webman-starter"]["1.1.0"] as $base |
   .packages["z0x/webman-starter-template"]["1.0.0"] as $template |
   $base.name == "z0x/webman-starter" and $base.type == "library" and
   $base.version == "1.1.0" and
   $base.dist == {type: "zip", url: $base_url, shasum: $base_sha1} and
   $template.name == "z0x/webman-starter-template" and $template.type == "project" and
   $template.version == "1.0.0" and $template.require[$base.name] == "^1.1.0" and
   $template.dist == {type: "zip", url: $template_url, shasum: $template_sha1}' \
  "$stage/packages.json"
```

## Install from candidate files

Create a separate catalog for the smoke test with `file://` URLs pointing to the candidate ZIPs. An isolated Composer home and cache ensure that the test reads the local catalog and downloads the candidate files. Packagist access and the package's required PHP extensions are needed for this test.

```bash
jq --arg stage "$stage" \
  '(.packages[][] | .dist.url) |= ("file://" + $stage + "/" + (split("/") | last))' \
  "$stage/packages.json" > "$stage/local-packages.json"
(
  export COMPOSER_HOME="$stage/composer-home" COMPOSER_CACHE_DIR="$stage/composer-cache"
  composer create-project --no-interaction --prefer-dist \
    --repository="$stage/local-packages.json" --add-repository \
    z0x/webman-starter-template "$stage/product" 1.0.0
  cd "$stage/product"
  composer check-platform-reqs
  jq -e --arg repo "file://$stage/local-packages.json" \
    'any(.repositories[]; .type == "composer" and .url == $repo)' composer.json
  jq -e --arg url "file://$stage/$base" \
    'any(.packages[]; .name == "z0x/webman-starter" and .version == "1.1.0" and .dist.url == $url)' \
    composer.lock
  test -f start.php
  test -f .env.example
  test ! -e .env
)
```

See the [template acceptance procedure](d2-template.md#验证候选包) for the database, migration, and HTTP tests.

## Publish after release approval

Tag the reviewed source revision with both release tags and upload each custom ZIP with its SHA-256 sidecar to the matching GitHub Release. Download both public assets over HTTPS and run `shasum -a 256 -c "$asset.sha256"` in the download directory with the corresponding local sidecars. Then place the generated `packages.json` at the root of the `composer` branch. Keep the tag and ZIP bytes fixed for each version so that installs from existing lock files continue to work. Publish corrections under new versions.

For subsequent releases, download the previous catalog and pass it with `--previous`. The builder accepts one or more version/ZIP pairs and refuses to replace a package/version already in the catalog. Review the catalog diff before publishing it.

```bash
repo_url=https://raw.githubusercontent.com/0xunknownz/webman-starter/composer
curl -fsSL "$repo_url/packages.json" -o "$stage/previous-packages.json"
php tools/build-release-catalog.php --previous "$stage/previous-packages.json" \
  1.1.1 "$stage/webman-starter-1.1.1.zip" > "$stage/next-packages.json"
```

After publication, verify the public catalog with a fresh Composer home and cache. The `--add-repository` flag saves the HTTPS repository URL in the generated project's manifest for later installs and updates.

```bash
repo_url=https://raw.githubusercontent.com/0xunknownz/webman-starter/composer
stage=$(mktemp -d)
curl -fsSL "$repo_url/packages.json" -o "$stage/published-packages.json"
(
  export COMPOSER_HOME="$stage/composer-home" COMPOSER_CACHE_DIR="$stage/composer-cache"
  composer create-project --no-interaction --prefer-dist \
    --repository="$repo_url" --add-repository \
    z0x/webman-starter-template "$stage/product" 1.0.0
  composer --working-dir="$stage/product" check-platform-reqs
)
```
