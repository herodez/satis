<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Builder;

use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PackagesBuilder extends Builder
{
    /** @var string included json filename template */
    private $includeFileName;
    /** @var array */
    private $writtenIncludeJsons = [];

    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors);
        
        $this->includeFileName = $config['include-filename'] ?? 'include/all$%hash%.json';
    }

    /**
     * @param PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages): void
    {
        $packagesByName = [];
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $packagesByName[$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }

        $repo = ['packages' => []];
        if (isset($this->config['providers']) && $this->config['providers']) {
            $providersUrl = 'p/%package%$%hash%.json';
            if (!empty($this->config['homepage'])) {
                $repo['providers-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $providersUrl;
            } else {
                $repo['providers-url'] = $providersUrl;
            }
            $repo['providers'] = [];
            $i = 1;
            // Give each version a unique ID
            foreach ($packagesByName as $packageName => $versionPackages) {
                foreach ($versionPackages as $version => $versionPackage) {
                    $packagesByName[$packageName][$version]['uid'] = $i++;
                }
            }
            // Dump the packages along with packages they're replaced by
            foreach ($packagesByName as $packageName => $versionPackages) {
                $dumpPackages = $this->findReplacements($packagesByName, $packageName);
                $dumpPackages[$packageName] = $versionPackages;
                $includes = $this->dumpPackageIncludeJson(
                    $dumpPackages,
                    str_replace('%package%', $packageName, $providersUrl),
                    'sha256'
                );
                $repo['providers'][$packageName] = current($includes);
            }
        } else {
            $repo = $this->dumpPackageIncludeJson($packagesByName, $this->includeFileName);
        }
        
    }

    private function findReplacements(array $packages, string $replaced): array
    {
        $replacements = [];
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $versionConfig) {
                if (!empty($versionConfig['replace']) && array_key_exists($replaced, $versionConfig['replace'])) {
                    $replacements[$packageName] = $packageConfig;
                    break;
                }
            }
        }

        return $replacements;
    }

    private function dumpPackageIncludeJson(array $packages, string $includesUrl, string $hashAlgorithm = 'sha1'): array
    {
        $filename = str_replace('%hash%', 'prep', $includesUrl);
        $path = $this->outputDir . '/' . ltrim($filename, '/');

        $repoJson = new JsonFile($path);
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->config['pretty-print'] ?? true) {
            $options |= JSON_PRETTY_PRINT;
        }
        
        
        $contents = $repoJson->encode(['packages' => $packages], $options) . "\n";
        $hash = hash($hashAlgorithm, $contents);
    
     
        if (false !== strpos($includesUrl, '%hash%')) {
            $this->writtenIncludeJsons[] = [$hash, $includesUrl];
            $filename = str_replace('%hash%', $hash, $includesUrl);
            if (file_exists($path = $this->outputDir . '/' . ltrim($filename, '/'))) {
                // When the file exists, we don't need to override it as we assume,
                // the contents satisfy the hash
                $path = null;
            }
        }
        
        return [
            'algo' => $hashAlgorithm, 'hash' => $hash, 'content' => $contents, 'filename' => $filename
        ];
    }
}
