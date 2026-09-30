# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project uses semantic versioning.

## [Unreleased]

### Changed

- Raise the minimum supported IP-Symcon version to 9.0 for the upcoming native Tile View.

### Fixed

- Clean up stale player registrations when Player instances are removed, including their associated queue and resynchronization state.
- Avoid the `InstanceInterface is not available` warning caused by Player cleanup during module unload or update.
- Register newly created Player instances when the IP-Symcon dataflow connection becomes available (`FM_CONNECT`), so players created through the native Configurator register with the Connection and receive their initial resynchronization.
- Keep playback metadata and cover artwork visible for `idle` queue updates while a valid current item is still present, avoiding flicker during transient Music Assistant state changes.

### Documentation

- Document the Player lifecycle behavior.
- Document the known transient Music Assistant playback-state and metadata behavior observed with Music Assistant Server 2.10.4.

## [2.0.1]

### Fixed

- Avoid Player cleanup during IP-Symcon kernel shutdown.
- Avoid warnings when normalizing existing volume-button profile associations.

## [2.0.0]

### Changed

- Use the common `MASS` prefix for public module commands.

### Documentation

- Add comprehensive German and English project documentation.

## [1.0.0]

### Added

- Initial stable release of the Music Assistant integration for IP-Symcon.
