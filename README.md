# laravel-retention

Declare data retention rules on Eloquent models, enforce them automatically and generate a deletion concept ("Löschkonzept", structured according to DIN 66398) from the same declarations.

> **Work in progress.** Not ready for use yet. See [SPEC.md](SPEC.md) for the planned feature set.

## Requirements

- PHP 8.4+
- Laravel 13

## Development

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan / Larastan, level 8
composer format    # Pint
```

## License

MIT. See [LICENSE.md](LICENSE.md).
