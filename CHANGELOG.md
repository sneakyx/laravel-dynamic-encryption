# Changelog

This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and uses [Semantic Versioning](https://semver.org/).

## [Unreleased]
### Added
- 

### Changed
- 

### Fixed
- 

### Deprecated
- 

### Removed
- 

### Security
- 

## [0.1.0] - 2025-11-14
### Added
- First public release of `sneakyx/laravel-dynamic-encryption`.
- Replacement of Laravel's default Encrypter with a dynamic encrypter that pulls the key/password at runtime from cache or database.
- KDF support (PBKDF2, Argon2id) including salt handling via `.env`.
- `DynamicEncryptable` trait for transparent field encryption in Eloquent models.
- CLI command `encrypt:rotate` for key rotation in batches.
- Configuration file `config/dynamic-encryption.php` and auto-discovery via service provider.
- Tests using `orchestra/testbench` and example configuration.

[Unreleased]: https://github.com/sneakyx/laravel-dynamic-encryption/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sneakyx/laravel-dynamic-encryption/releases/tag/v0.1.0
