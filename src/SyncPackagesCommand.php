<?php

namespace Credevator\ComposerSyncPackagesPlugin;

use Composer\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncPackagesCommand extends BaseCommand
{
    protected static $defaultName = 'sync-packages';

    protected function configure()
    {
        $this
            ->setDescription('Sync packages and repositories from a source Composer project to the target project')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to the source Composer project')
            ->addOption('include-subpackage', null, InputOption::VALUE_OPTIONAL, 'Include dependencies from the specified subpackage');
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourcePath = $input->getArgument('source');
        $subpackage = $input->getOption('include-subpackage');

        // Load the source and target composer.json files
        $sourceComposerPath = $sourcePath . '/composer.json';
        $targetComposerPath = getcwd() . '/composer.json';

        if (!file_exists($sourceComposerPath) || !file_exists($targetComposerPath)) {
            $output->writeln('<error>Invalid source or target path, or composer.json not found.</error>');
            return BaseCommand::FAILURE;
        }
        $sourceComposerData = json_decode(file_get_contents($sourceComposerPath), true);
        $targetComposerData = json_decode(file_get_contents($targetComposerPath), true);

        // If subpackage is provided, load its dependencies
        if ($subpackage) {
            $subpackageData = $this->loadSubpackageData($subpackage, $sourceComposerData, $sourcePath, $output);
            if ($subpackageData) {
                $this->mergeComposerData($sourceComposerData, [$subpackageData]);
            }
        }

        // Check and compare packages
        $packagesUpdated = $this->syncPackages($sourceComposerData, $targetComposerData, $output);

        // Sync repositories
        $repositoriesSynced = $this->syncRepositories($sourceComposerData, $targetComposerData, $output);
        // Sync patches
        $patchesUpdated = $this->syncPatches($sourceComposerData, $targetComposerData, $output);
        if ($packagesUpdated || $repositoriesSynced || $patchesUpdated) {
            $output->writeln('<info>Composer update may be required to apply changes.</info>');
        } else {
            $output->writeln('<info>No updates, repository, or patch changes necessary.</info>');
        }

        return BaseCommand::SUCCESS;
    }

    private function loadComposerData(string $path): array
    {
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function loadSubpackageData(string $subpackage, array $sourceComposerData, string $sourcePath, OutputInterface $output): ?array
    {
        if (!isset($sourceComposerData['require'][$subpackage])) {
            $output->writeln("<error>Subpackage $subpackage not found in the source project.</error>");
            return null;
        }

        $subpackagePath = $sourcePath . '/vendor/' . str_replace('/', DIRECTORY_SEPARATOR, $subpackage) . '/composer.json';

        if (!file_exists($subpackagePath)) {
            $output->writeln("<error>composer.json for subpackage $subpackage not found.</error>");
            return null;
        }

        $output->writeln("<info>Loading dependencies from subpackage: $subpackage</info>");
        return $this->loadComposerData($subpackagePath);
    }

    private function mergeComposerData(array &$sourceData, array $additionalData)
    {
        foreach ($additionalData as $data) {
            if (isset($data['require'])) {
                $sourceData['require'] = array_merge($sourceData['require'], $data['require']);
            }
            if (isset($data['repositories'])) {
                $sourceData['repositories'] = array_merge($sourceData['repositories'] ?? [], $data['repositories']);
            }
        }
    }

    private function syncPackages(array $sourceData, array &$targetData, OutputInterface $output): bool
    {
        $packagesUpdated = false;

        if (isset($sourceData['require'])) {
            foreach ($sourceData['require'] as $package => $sourceVersion) {
                if (!isset($targetData['require'][$package])) {
                    $output->writeln("<info>Adding package: $package, version: $sourceVersion</info>");
                    $targetData['require'][$package] = $sourceVersion;
                    $packagesUpdated = true;
                } elseif (version_compare($targetData['require'][$package], $sourceVersion, '<')) {
                    $output->writeln("<info>Updating package: $package from version {$targetData['require'][$package]} to $sourceVersion</info>");
                    $targetData['require'][$package] = $sourceVersion;
                    $packagesUpdated = true;
                }
            }
        }

        if ($packagesUpdated) {
            ksort($targetData['require']);
            file_put_contents(getcwd() . '/composer.json', json_encode($targetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $packagesUpdated;
    }

    private function syncRepositories(array $sourceData, array &$targetData, OutputInterface $output): bool
    {
        $repositoriesSynced = false;

        if (isset($sourceData['repositories'])) {
            if (!isset($targetData['repositories'])) {
                $targetData['repositories'] = [];
            }

            foreach ($sourceData['repositories'] as $repository) {
                if (!in_array($repository, $targetData['repositories'])) {
                    $output->writeln("<info>Adding repository: " . json_encode($repository) . "</info>");
                    $targetData['repositories'][] = $repository;
                    $repositoriesSynced = true;
                }
            }
        }

        if ($repositoriesSynced) {
            file_put_contents(getcwd() . '/composer.json', json_encode($targetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return $repositoriesSynced;
    }

    private function syncPatches(array $sourceData, array &$targetData, OutputInterface $output): bool
    {
        $patchesUpdated = false;
        if (!isset($sourceData['extra']['patches']) || !is_array($sourceData['extra']['patches'])) {
            return false;
        }
        if (!isset($targetData['extra']['patches'])) {
            $targetData['extra']['patches'] = [];
        }
        $existingPatches = [];
        // Collect all existing patches in the target by value
        foreach ($targetData['extra']['patches'] as $package => $patchList) {
            foreach ($patchList as $patchDescription => $patchValue) {
                $existingPatches[$patchValue] = $package;
            }
        }
        foreach ($sourceData['extra']['patches'] as $package => $patchList) {
            foreach ($patchList as $patchDescription => $patchValue) {
                if (!in_array($patchValue, array_keys($existingPatches))) {
                    $output->writeln("<info>Adding patch for $package: $patchValue</info>");
                    if (!isset($targetData['extra']['patches'][$package])) {
                        $targetData['extra']['patches'][$package] = [];
                    }
                    // Use a new key for the patch description to avoid conflicts
                    $targetData['extra']['patches'][$package][$patchDescription] = $patchValue;
                    $patchesUpdated = true;
                }
            }
        }
        if ($patchesUpdated) {
            file_put_contents(getcwd() . '/composer.json', json_encode($targetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return $patchesUpdated;
    }
}