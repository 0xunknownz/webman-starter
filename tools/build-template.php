<?php

declare(strict_types=1);

if ($argc !== 3 || preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php tools/build-template.php <minimum-starter-version> <output.zip>\n");
    exit(2);
}

[, $starterVersion, $output] = $argv;
$root = dirname(__DIR__);
$example = "$root/examples/webman";

try {
    // Local vendor files, credentials, and runtime state must not enter the dist.
    exec('git -C ' . escapeshellarg($example) . ' ls-files', $files, $status);
    if ($status !== 0 || !in_array('composer.json', $files, true)) {
        throw new RuntimeException('Could not list tracked Webman template files.');
    }

    $manifest = json_decode(file_get_contents("$example/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $manifest['name'] = 'z0x/webman-starter-template';
    $manifest['description'] = 'Webman project template for Webman Starter.';
    $manifest['homepage'] = 'https://github.com/0xunknownz/webman-starter';
    $manifest['support'] = ['issues' => 'https://github.com/0xunknownz/webman-starter/issues'];
    $manifest['require']['z0x/webman-starter'] = '^' . $starterVersion;
    unset($manifest['repositories'], $manifest['scripts'], $manifest['scripts-descriptions']);

    $archive = new ZipArchive();
    if ($archive->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException("Cannot create $output. Use a new ZIP path in an existing directory.");
    }
    foreach ($files as $file) {
        if (in_array($file, ['composer.json', 'composer.lock'], true)
            || str_starts_with($file, 'vendor/') || str_starts_with($file, 'runtime/')
            || (str_starts_with(basename($file), '.env') && basename($file) !== '.env.example')) {
            continue;
        }

        $source = "$example/$file";
        if (is_link($source) || !is_file($source) || !$archive->addFile($source, $file)
            || !$archive->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, fileperms($source) << 16)) {
            throw new RuntimeException("Could not package $file.");
        }
    }
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!$archive->addFromString('composer.json', $json . "\n") || !$archive->close()) {
        throw new RuntimeException("Could not write $output.");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
