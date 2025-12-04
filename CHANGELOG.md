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

## [0.1.4] - 2025-12-04
### Added
- Trait for encrypting data attributes for tests.

## [0.1.3] - 2025-12-02
### Changed
- Removed the idea of using APP_KEY as a data decryption fallback for DATA decryption.

## [0.1.2] - 2025-11-14
### Changed
- Documentation updates (README, docs/*): the text was in german language.

## [0.1.1] - 2025-11-14
### Added
- EncryptedNullableCast that safely returns a LockedEncryptedValue when the decryption key is not available (Variante A behavior).
- LockedEncryptedValue value object to represent a locked/undecryptable attribute without throwing exceptions.
- Unit test CastLockedTest to ensure cast returns a LockedEncryptedValue when the bundle/key is missing.

### Changed
- Removed the idea of using APP_KEY as a data decryption fallback for DATA decryption. When the dynamic key bundle is missing, model attributes using the provided cast will no longer throw or try a wrong key; they will resolve to a LockedEncryptedValue instead.


### Fixed
- Clarified configuration examples and environment variables in README.

### Security
- Reduced risk of silent mis-decryption by eliminating the APP_KEY fallback path for data reads.

## [0.1.0] - 2025-11-14
### Added
- First public release of `sneakyx/laravel-dynamic-encryption`.
- Replacement of Laravel's default Encrypter with a dynamic encrypter that pulls the key/password at runtime from cache or database.
- KDF support (PBKDF2, Argon2id) including salt handling via `.env`.
- `DynamicEncryptable` trait for transparent field encryption in Eloquent models.
- CLI command `encrypt:rotate` for key rotation in batches.
- Configuration file `config/dynamic-encryption.php` and auto-discovery via service provider.
- Tests using `orchestra/testbench` and example configuration.

[Unreleased]: https://github.com/sneakyx/laravel-dynamic-encryption/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/sneakyx/laravel-dynamic-encryption/compare/v0.1.0...v0.1.2
[0.1.0]: https://github.com/sneakyx/laravel-dynamic-encryption/releases/tag/v0.1.0
