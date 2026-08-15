# rasuvaeff/property-testing-phpunit

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-phpunit/v)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-phpunit/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![Build](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-phpunit/php)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[English version](README.md)

PHPUnit-адаптер
[property-testing движка](https://github.com/rasuvaeff/property-testing-core):
трейт `PropertyTesting` с fluent-API `forAll()->check()` поверх
framework-agnostic раннера. Сотни случайных входов на тест, поиск падающего и
shrink до минимального контрпримера, который реально можно прочитать — внутри
обычного PHPUnit `TestCase`.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный API-справочник, который можно показать модели.

## Часть семейства property-testing

| Пакет | Когда использовать |
|---|---|
| [`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core) | Вы управляете движком сами: собственный harness, CI-страж, CLI-проверка или адаптер другого фреймворка |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | Вы тестируете на [Testo](https://github.com/php-testo/testo) — drop-in замена замороженного `rasuvaeff/property-testing` с тем же атрибутом `#[Property]` |
| **`rasuvaeff/property-testing-phpunit`** (этот пакет) | Вы тестируете на PHPUnit — трейт `PropertyTesting` с fluent-API `forAll()->check()` |

## Требования

- PHP 8.3+
- [`phpunit/phpunit`](https://packagist.org/packages/phpunit/phpunit) `^11.5 || ^12.0 || ^13.0`
- [`rasuvaeff/property-testing-core`](https://packagist.org/packages/rasuvaeff/property-testing-core) `^0.1`

PHPUnit 13 требует PHP 8.4.1 или новее. На PHP 8.3 Composer выбирает
совместимый релиз PHPUnit 11 или 12.

## Установка

```bash
composer require --dev rasuvaeff/property-testing-phpunit
```

Никакой конфигурации не нужно: подмешайте трейт в `TestCase` и вызывайте
`forAll()` из тестового метода.

## Использование

Сопоставьте каждому параметру тела property генератор, настройте прогон
fluent-цепочкой и передайте property в `check()`. Движок генерирует случайные
аргументы, выполняет замыкание нужное число раз и при первом падении
shrink-ает контрпример до минимального:

```php
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;

final class SortPropertyTest extends TestCase
{
    use PropertyTesting;

    public function testSortIsIdempotent(): void
    {
        $this->forAll(['values' => Gen::arrayOf(Gen::int())])
            ->runs(300)
            ->check(static function (array $values): void {
                sort($values);
                $once = $values;
                sort($values);

                self::assertSame($once, $values);
            });
    }
}
```

**Имена параметров замыкания выбирают генераторы** — ровно как сигнатура
`#[Property]`-метода в Testo-адаптере. При падении тест падает с сообщением
движка:

```
Property falsified after 12 successful run(s); seed=7382910
  Original: values=[20, 82, 44, 43, 29, 47, 29, 0, … +4 more]
  Shrunk:   values=[0, 0, 0, 0, 0, 0] (7 shrink step(s), 29 trial(s))
  Changed:  values=[20, 82, 44, …] -> [0, 0, 0, 0, 0, 0]
```

Воспроизвести точный прогон: запиньте seed из вывода — `->seed(7382910)`.

### Fluent-цепочка

`forAll()` возвращает `PropertyCheck`; каждый сеттер возвращает его же,
`check()` запускает property.

| Метод | Значение |
|---|---|
| `id(string)` | Именует property вместо id, выведенного из вызывающего метода. Ключует корпус и события и служит отображаемым именем — обязателен, когда `forAll()` исполняется внутри замыкания |
| `runs(int)` | Сколько успешных проверок выполнить (по умолчанию 100). Discard-прогоны не считаются |
| `seed(int)` | Пинит random-фазу для воспроизведения. Одновременно отключает replay корпуса — запиненный прогон важнее |
| `maxShrinks(int)` | Потолок принятых shrink-шагов; `0` выключает shrinking |
| `maxDiscards(int)` | Бюджет discard'ов до провала с `GaveUpException`; по умолчанию `runs * 10` |
| `timeoutMs(int)` | Wall-clock дедлайн одного прогона — превышение валит property с `DeadlineExceededException` |
| `budgetMs(int)` | Wall-clock бюджет всей random-фазы — исчерпание валит с `TimeBudgetExceededException` |
| `examples(array)` | Фиксированные позиционные кортежи аргументов, выполняются **до** random-фазы; упавший пример останавливает прогон и не shrink-ается |
| `listeners(...)` | `PropertyListener`-наблюдатели событий жизненного цикла движка |
| `shrink(ShrinkMode)` | Насколько усердно минимизировать: `Full` (по умолчанию), `Off` (сообщить вход как сгенерирован), `Bounded` с бюджетом |
| `shrinkBudgetMs(int)` | Бюджет спуска в реальном времени — единственная ручка, которая стоит детерминизма: докуда дойдёт спуск, зависит от того, как долго исполняется тело, поэтому на быстрой и медленной машине минимизация может закончиться по-разному |
| `phases(array)` | Какие стадии выполнять (`Phase::Examples`, `Corpus`, `Random`, `Shrink`); подмножество осознанно обменивает покрытие на время прогона |
| `derandomize(bool)` | Выводит незаданный seed из id property вместо случайного; явный `seed()` всё равно побеждает |
| `path(string)` | Воспроизводит записанный спуск shrink вместо повторного поиска; нужен seed того прогона |
| `edgeCases(EdgeCases)` | `None` выключает граничное смещение числовых генераторов — для property, которой края стоят только прогонов |
| `output($stdout, $stderr)` | Перенаправляет отчёт распределения, предупреждение о discard'ах и verbose-трассу (используется тестами самого пакета) |

### Имя property (`id()`)

`id()` именует property:

```php
$this->forAll(['values' => Gen::arrayOf(Gen::int())])
    ->id('sort::idempotent')
    ->runs(300)
    ->check(static function (array $values): void { /* … */ });
```

Без него имя выводится из вызывающего метода — что верно для метода теста и
неверно для **замыкания**: стабильного имени у замыкания в PHP нет. На PHP 8.3
все замыкания класса — `{closure}`, поэтому две property одного файла делят
ключ корпуса и затирают контрпримеры друг друга; с 8.4 имя выглядит как
`{closure:/path/File.php:19}`, и вставка строки выше осиротит вчерашнюю
запись. Ничего при этом не бросается — корпус просто перестаёт воспроизводить
падение, ради которого существует.

Поэтому вызывайте `id()` всегда, когда `forAll()` исполняется внутри замыкания, а не
напрямую в методе теста (обычный источник — `it()` и `test()` в Pest). Id
ключует регрессионный корпус и все события и одновременно становится
отображаемым именем: одна строка идентифицирует property и в корпусе, и в
событиях, и в печатном выводе.

### Как результаты ложатся на PHPUnit

- **Пасс** засчитывает одну assertion — тест никогда не помечается risky.
- Каждый **непроходной исход** (falsified, gave up, непокрытый cover, deadline,
  budget, отказ генерации, упавший example, воспроизведённая регрессия)
  становится **одним `AssertionFailedError`**: сообщение — родное сообщение
  движка (seed, original/shrunk аргументы, статистика shrink), а `previous` —
  engine-исключение (`PropertyViolationException`, `GaveUpException`,
  `RegressionViolationException`, …).
- `Assume::that()` — это **discard прогона внутри property**, движок повторяет
  попытку; PHPUnit-тест никогда не помечается skipped.

### Переменные окружения

Побайтовый паритет с Testo-адаптером — один контракт на все адаптеры:

| Переменная | Эффект |
|---|---|
| `PROPERTY_RUNS` | Положительное целое, переопределяет число прогонов каждой property (поднять runs в CI) |
| `PROPERTY_SEED` | Целочисленный seed для property без явного `seed()` (реплей всего suite). Явный `seed()` важнее |
| `PROPERTY_VERBOSE` | Любое значение кроме `''`/`'0'` логирует аргументы каждого прогона и каждый принятый shrink-шаг |
| `PROPERTY_DB` | Путь к каталогу, включающий регрессионный корпус, либо DSN `redis://host[:port][/key-prefix]` для корпуса, общего между CI и разработчиками. Не задан — выключен, ничего не пишется |
| `PROPERTY_PHASES` | Список стадий через запятую (`examples,corpus,random,shrink`, регистр не важен), перекрывающий `phases()`; неизвестное имя — исключение, а не пропуск стадии. `examples,corpus` — быстрый гейт для pull request |
| `PROPERTY_DERANDOMIZE` | Любое значение, кроме `''`/`'0'`, выводит каждый незаданный seed из id property: весь сьют становится воспроизводимым без правки кода |
| `PROPERTY_PATH` | Записанный спуск shrink (`CounterExample::$path`) воспроизводится вместо повторного поиска. Нужен seed того прогона; явный `path()` побеждает |
| `PROPERTY_EDGE_CASES` | `mixin` или `none` (регистр не важен) — граничное смещение для всего сьюта, перекрывает `edgeCases()`. Неизвестное значение — исключение |

`PROPERTY_DB` принимает либо каталог, либо Redis-DSN:

```bash
PROPERTY_DB=/tmp/corpus                  vendor/bin/phpunit   # одна машина
PROPERTY_DB=redis://127.0.0.1:6379       vendor/bin/phpunit   # общий
PROPERTY_DB=redis://redis:6379/suite-a:  vendor/bin/phpunit   # общий сервер, свой префикс
```

Каталог помнит контрпример для того, кто им владеет, — в CI это машина,
которую удаляют вместе с job'ом. Redis-форма — тот же корпус в том же
документе, но общий. Нужен `ext-redis` или `predis/predis`; отсутствие обоих —
ошибка, а не тихий откат на файловую систему.

Формат корпуса — ровно тот, что писал `rasuvaeff/property-testing` 2.8:
корпус, записанный под Testo (или под 2.x), реплеится здесь, и наоборот. При
falsification записывается минимальный вход; следующий прогон реплеит
записанные падения **первыми** (если property не запинена `seed()`) и
всё ещё красное падение репортит как `RegressionViolationException`;
позеленевшее — вычищается.

### Распределение и discard'ы

`Classify::label()`/`when()`/`cover()` работают внутри тела property. Когда
классифицированная property проходит, адаптер печатает распределение меток:

```
Property "testSortKeepsEveryElement" distribution: long 39% (77/200), short 61% (123/200)
```

Property, отбрасывающая больше 90% попыток (через `Assume::that()`), получает
предупреждение с советом сузить генераторы.

### Почему нет атрибута `#[Property]`?

Публичный extension/event API PHPUnit наблюдает за выполнением тестов, но не
даёт устойчивого контракта для перехвата и многократного повторного вызова
тестового метода — а это ровно то, что обязан делать property-атрибут. Этот
адаптер сознательно не зависит от внутренностей PHPUnit; fluent-API хватает
документированной поверхности. Атрибут может появиться позже — только если
его можно построить на документированном extension API поддерживаемых мажоров.

### Генераторы

Полный каталог генераторов (`Gen::int()` … `Gen::subset()`, `Gen::regex()`,
`Gen::commands()`, `Gen::draw()`, `Shrinkable`, собственные
`ArbitraryInterface`, stateful/model-based тестирование) — это API движка,
задокументированный в
[README core](https://github.com/rasuvaeff/property-testing-core#generators).
Всё оттуда используется из замыкания `check()` как есть.

## Публичный API этого пакета

| Тип | Роль |
|---|---|
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` | Трейт, который подмешивается в `TestCase`; единственная точка входа — `forAll()` |
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck` | Fluent-строитель: резолвит цепочку и окружение в core `PropertyDefinition`, запускает движок, мапит структурный результат на PHPUnit |
| `Rasuvaeff\PropertyTesting\PhpUnit\VerboseListener` | Вывод `PROPERTY_VERBOSE` как exception-hardened слушатель движка (internal) |

## Безопасность

Сгенерированные значения псевдослучайны (seeded MT19937), не криптографичны.
Seed — не секрет: он намеренно печатается в выводе падения. Файлы корпуса
`PROPERTY_DB` — тестовые артефакты: они содержат сгенерированные входы как
есть, не указывайте переменную на публикуемый каталог.

## Примеры

См. [examples/](examples/) — полный property-based `TestCase`:

```bash
vendor/bin/phpunit examples/SortPropertyTest.php
```

## Разработка

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + тесты
make cs-fix      # применить code style
make mutation    # мутационное тестирование infection
```

Тесты гоняются через PHPUnit (`composer test` — это `phpunit`), не Testo.

## Лицензия

[BSD-3-Clause](LICENSE.md)
