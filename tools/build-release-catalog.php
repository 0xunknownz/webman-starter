<?php

declare(strict_types=1);

const RELEASE_DOWNLOADS = 'https://github.com/0xunknownz/webman-starter/releases/download';
const RELEASE_PACKAGES = [
    'z0x/webman-starter' => 'library',
    'z0x/webman-starter-template' => 'project',
];

function releasePackage(string $version, string $path): stdClass
{
    if (preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $version) !== 1) {
        throw new RuntimeException("Expected a stable version without a v prefix, got $version.");
    }

    $zip = new ZipArchive();
    if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException("Could not open release ZIP $path.");
    }
    $json = $zip->getFromName('composer.json');
    $zip->close();
    if ($json === false) {
        throw new RuntimeException("Missing composer.json at the root of $path.");
    }
    $manifest = json_decode($json, flags: JSON_THROW_ON_ERROR);
    $name = $manifest->name ?? null;
    if (!is_string($name) || !isset(RELEASE_PACKAGES[$name]) || ($manifest->type ?? null) !== RELEASE_PACKAGES[$name]) {
        throw new RuntimeException("Unexpected Composer package name or type in $path.");
    }
    if (isset($manifest->version) && $manifest->version !== $version) {
        throw new RuntimeException("The Composer version in $path does not match $version.");
    }

    $filename = substr($name, strlen('z0x/')) . "-$version.zip";
    if (basename($path) !== $filename) {
        throw new RuntimeException("Expected ZIP filename $filename for $name $version.");
    }
    $shasum = sha1_file($path);
    if ($shasum === false) {
        throw new RuntimeException("Could not hash $path.");
    }

    $tag = $manifest->type === 'project' ? "template-v$version" : "v$version";
    unset($manifest->source, $manifest->version_normalized);
    $manifest->version = $version;
    $manifest->dist = ['type' => 'zip', 'url' => RELEASE_DOWNLOADS . "/$tag/$filename", 'shasum' => $shasum];

    return $manifest;
}

$arguments = array_slice($argv, 1);
$previous = null;
if (($arguments[0] ?? null) === '--previous') {
    $previous = $arguments[1] ?? '';
    $arguments = array_slice($arguments, 2);
}
if ($previous === '' || $arguments === [] || count($arguments) % 2 !== 0) {
    fwrite(STDERR, "Usage: php tools/build-release-catalog.php [--previous packages.json] <version> <release.zip> [...]\n");
    exit(2);
}

try {
    $packages = [];
    if ($previous !== null) {
        $json = is_file($previous) ? file_get_contents($previous) : false;
        if ($json === false) {
            throw new RuntimeException("Could not read previous catalog $previous.");
        }
        $catalog = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (!is_object($catalog->packages ?? null) || array_keys((array) $catalog) !== ['packages']) {
            throw new RuntimeException("Expected an inline packages catalog in $previous.");
        }
        foreach ($catalog->packages as $name => $versions) {
            if (!isset(RELEASE_PACKAGES[$name]) || !is_object($versions)) {
                throw new RuntimeException("Unexpected package entry $name in $previous.");
            }
            $packages[$name] = (array) $versions;
        }
    }

    foreach (array_chunk($arguments, 2) as [$version, $path]) {
        $package = releasePackage($version, $path);
        $name = $package->name;
        if (array_key_exists($version, $packages[$name] ?? [])) {
            throw new RuntimeException("Catalog already contains $name $version. Use a new version for a new release.");
        }
        $packages[$name][$version] = $package;
    }
    ksort($packages);
    foreach ($packages as $name => $versions) {
        ksort($versions);
        $packages[$name] = $versions;
    }
    echo json_encode(['packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
