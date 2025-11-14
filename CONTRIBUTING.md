# Contributing to sneakyx/laravel-dynamic-encryption

Thank you for your interest in contributing! This document explains how to report bugs, propose features, and submit code changes.

## Table of Contents
- Reporting bugs and feature requests
- Security disclosures
- Local development setup
- Tests and code style
- Branch and commit strategy
- Release process
- Code of Conduct

## Reporting bugs and feature requests
- Open an issue at: https://github.com/sneakyx/laravel-dynamic-encryption/issues
- Please include:
  - Expected vs. actual behavior
  - Steps to reproduce (code/config/ENV redacted as needed)
  - Environment (PHP, Laravel version, package version, OS)
  - Relevant logs/stack traces (no secrets)

## Security disclosures
Please do NOT report security issues publicly via issues or pull requests. Follow the instructions in `SECURITY.md` for confidential reporting.

## Local development (standalone package)
Requirements: PHP >= 8.1, Composer.

```bash
composer install
```

Optional: PDO extensions and `ext-sodium` depending on your selected KDF.

## Tests and code style
- Run tests:
  ```bash
  vendor/bin/phpunit
  ```
- The package uses `orchestra/testbench` for Laravel integration testing.
- Code style: PSR-12. If available, use `laravel/pint`:
  ```bash
  ./vendor/bin/pint
  ```
- Before opening a PR, ensure tests pass and new code is covered by tests.

## Branch and commit strategy
- Fork the repository and work on a feature branch (`feature/...`) or fix branch (`fix/...`).
- Write meaningful commit messages. Conventional Commits style is welcome (`feat:`, `fix:`, `chore:`, `docs:` etc.).
- A pull request should:
  - Explain the "why" and "how"
  - Contain no secrets/keys/passwords
  - Clearly mark breaking changes and document them in `CHANGELOG.md`

## Release process (maintainers)
- After merge: update `CHANGELOG.md`.
- Tag a semantic version, e.g., `v0.2.0`.
- Create a GitHub release; Packagist updates automatically if integration is enabled.

## Code of Conduct
All contributions are subject to the [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md).
