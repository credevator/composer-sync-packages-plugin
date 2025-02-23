<?php

namespace Credevator\ComposerSyncPackagesPlugin;

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
            ->addArgument('source', InputArgument::REQUIRED, 'Path to the source Composer project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourcePath = $input->getArgument('source');

        // Load the source and target composer.json files
        $sourceComposerPath = $sourcePath . '/composer.json';
        $targetComposerPath = getcwd() . '/composer.json';

        if (!file_exists($sourceComposerPath) || !file_exists($targetComposerPath)) {
            $output->writeln('<error>Invalid source or target path, or composer.json not found.</error>');
            return Command::FAILURE;
        }

        $sourceComposerData = json_decode(file_get_contents($sourceComposerPath), true);
        $targetComposerData = json_decode(file_get_contents($targetComposerPath), true);

        // Check and compare packages
        $packagesUpdated = $this->syncPackages($sourceComposerData, $targetComposerData, $output);

        // Sync repositories
        $repositoriesSynced = $this->syncRepositories($sourceComposerData, $targetComposerData, $output);

        if ($packagesUpdated || $repositoriesSynced) {
            $output->writeln('<info>Composer update may be required to apply changes.</info>');
        } else {
            $output->writeln('<info>No updates or repository changes necessary.</info>');
        }

        $output->writeln('<warn>Please verify your updated composer.json before running composer update.</warn>');
        return BaseCommand::SUCCESS;
    }

    private function syncPackages(array $sourceData, array &$targetData, OutputInterface $output): bool
    {
        $packagesUpdated = false;

        if (isset($sourceData['require'])) {
            foreach ($sourceData['require'] as $package => $sourceVersion) {
                // Check if the package is present and compare versions
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
 }
