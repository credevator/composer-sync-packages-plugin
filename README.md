# Composer Plugin: Sync Packages.

## Objective

Use this plugin to merge individual Drupal packages into a single repository. 
This is useful when you have multiple projects and you want to merge them together in a single repository.
The only thing you need is merge the installed packages into the main repository with highest used module version.

## Installation

```bash
composer global require credevator/composer-plugin-sync-packages
```

## Usage

```bash
composer global sync-packages [PATH_TO_OTHER_PROJECT]
```

## Implementation
- It will check and compare all installed packages from the source repository to the target repository.
- Then add or update the package in the target repository with the highest used module version.
- It also adds the used repositories to the target repository.
- [WIP] Merge patches.
