# Examples

Runnable examples for `rasuvaeff/property-testing-phpunit`.

| Example | Shows | Needs server? |
|---|---|---|
| `SortPropertyTest.php` | A complete property-based PHPUnit `TestCase`: the `PropertyTesting` trait, the fluent `forAll()->runs()->check()` chain, `Classify::when()` distribution labels, and an `Assume::that()` discard — four properties over plain string/sort functions, including one with `auto()`-derived generators | No |

## Running

The examples are ordinary PHPUnit test cases. Run them from the package root
after `composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpunit examples/SortPropertyTest.php
```
