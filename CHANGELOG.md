# Changelog

This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and uses [Semantic Versioning](https://semver.org/).

## [Unreleased]
### Added
- EncryptedNullableCast that safely returns a LockedEncryptedValue when the decryption key is not available (Variante A behavior).
- LockedEncryptedValue value object to represent a locked/undecryptable attribute without throwing exceptions.

### Changed
- Removed the idea of using APP_KEY as a data decryption fallback. When the dynamic key bundle is missing, model attributes using the provided cast will no longer throw or try a wrong key; they will resolve to a LockedEncryptedValue instead.
- Documentation updated to recommend the cast-based flow (prompt user to unlock) instead of automatic fallback.

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
