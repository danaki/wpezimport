# WP EZ Import Tests

This directory contains unit tests for the WP EZ Import plugin.

## Running Tests

To run the tests, you'll need to have PHPUnit and WP_Mock installed. These are already included in the project's `composer.json`.

### Setup

1. Make sure you have Composer installed: https://getcomposer.org/download/

2. Install dependencies:

```bash
composer install
```

### Running All Tests

From the main plugin directory, run:

```bash
./vendor/bin/phpunit
```

This will use the configuration in `phpunit.xml` to automatically bootstrap the testing environment and run all tests.

### Running Specific Tests

To run a specific test file:

```bash
./vendor/bin/phpunit tests/FindRepeatingElementsTest.php
```

To run a specific test method:

```bash
./vendor/bin/phpunit --filter=testFindRepeatingElementsSimple tests/FindRepeatingElementsTest.php
```

## Test Structure

- `bootstrap.php`: Sets up the test environment
- `FindRepeatingElementsTest.php`: Tests for the `wpezimport_find_repeating_elements` function
- Additional test files should be named with a `Test.php` suffix

## Coverage Reports

To generate a code coverage report:

```bash
./vendor/bin/phpunit --coverage-html ./coverage
```

This will create a HTML coverage report in the `coverage` directory.
