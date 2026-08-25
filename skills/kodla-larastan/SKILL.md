---
name: kodla-larastan
description: >-
  Устанавливает и настраивает статический анализ PHPStan/Larastan для Laravel-проекта:
  larastan/larastan, shipmonk/dead-code-detector, tomasvotruba/bladestan, генерирует
  phpstan.neon, поднимает лимит памяти через composer-скрипт, предлагает baseline
  для существующих проектов. Используй когда пользователь просит "добавь статический
  анализ", "настрой phpstan", "добавь larastan", "настрой phpstan для laravel".
argument-hint: "[--baseline|--no-baseline]"
allowed-tools: Read Write Edit Glob Grep Bash(composer require --dev *) Bash(vendor/bin/phpstan *) AskUserQuestion
metadata:
  author: Kodla
  version: "1.0"
  category: static-analysis
---

# Larastan — Статический анализ для Laravel

Устанавливает и настраивает PHPStan/Larastan в Laravel-проекте: dev-зависимости, `phpstan.neon`, лимит памяти по умолчанию, опциональный baseline для существующих проектов.

**Два режима:**
- **Create** — `phpstan.neon`/`phpstan.neon.dist` отсутствуют → создать с нуля по шаблону
- **Enhance** — файл уже есть → предложить добавить недостающие `includes`, не трогая пользовательские `level`/`paths`

---

## Шаг 0: Загрузка контекста

Прочитать:
- `composer.json` (обязательно — без него навык неприменим: сообщить «Не найден composer.json — этот навык для PHP/Composer-проектов» и остановиться)
- `.kodla/config.yaml` если существует — только `language.ui` (на каком языке общаться с пользователем; конфиги PHPStan не переводятся, `language.artifacts` этому навыку не нужен)
- `.kodla/skill-context/kodla-larastan/SKILL.md` если существует — правила из него имеют приоритет над этим файлом

---

## Шаг 1: Анализ проекта

### 1.1 Определение Laravel

Проверить `composer.json` → `require` (или `require-dev`) на наличие `laravel/framework`.

- **Найден** → продолжить.
- **Не найден** → `AskUserQuestion`:
  ```
  В composer.json не найден laravel/framework — этот навык рассчитан на Laravel-проекты.
  Продолжить всё равно?
  Варианты: Да / Нет
  ```
  При отказе — СТОП.

### 1.2 Определение режима

Glob: `phpstan.neon`, `phpstan.neon.dist`

- **Найден** → `MODE = enhance`
- **Не найден** → `MODE = create`

### 1.3 Уже установленные пакеты

По `composer.json` (`require`/`require-dev`, без обращения к сети) определить какие из трёх пакетов уже присутствуют:
- `larastan/larastan`
- `shipmonk/dead-code-detector`
- `tomasvotruba/bladestan`

---

## Шаг 2: Вопросы пользователю

### 2.1 Baseline

Спросить, только если `MODE == create`, либо если `phpstan.neon` уже есть, но `phpstan-baseline.neon` — нет.

Если в `$ARGUMENTS` передано `--baseline` или `--no-baseline` — использовать это значение и не спрашивать.

Иначе:
```
AskUserQuestion: Это существующий проект с накопленным кодом, или новый/чистый Laravel-проект?
Варианты:
1. Существующий — накопленный код, много потенциальных ошибок (сгенерировать baseline)
2. Новый/чистый — анализировать всё с нуля
```

### 2.2 Enhance-подтверждение

Только если `MODE == enhance` и в существующем `phpstan.neon` отсутствуют какие-то из ожидаемых `includes`:
```
AskUserQuestion: В phpstan.neon отсутствуют: <список отсутствующих includes>. Добавить?
Варианты: Да / Нет
```
При отказе — не изменять `phpstan.neon`, перейти к шагу 3.5 (лимит памяти) как есть.

---

## Шаг 3: Установка и генерация

### 3.1 Установка dev-зависимостей

Собрать список пакетов из {`larastan/larastan`, `shipmonk/dead-code-detector`, `tomasvotruba/bladestan`}, которые ещё не установлены (см. 1.3), и установить одной командой:

```bash
composer require --dev larastan/larastan shipmonk/dead-code-detector tomasvotruba/bladestan
```

(подставить только реально отсутствующие пакеты; если все три уже установлены — пропустить шаг).

### 3.2 Проверка carbon-расширения

После установки проверить (Glob) существование `vendor/nesbot/carbon/extension.neon`.
- **Есть** → включить строку `vendor/nesbot/carbon/extension.neon` в `includes`.
- **Нет** (старая версия Carbon без PHPStan-экстеншена) → пропустить строку молча.

### 3.3 Генерация/дополнение `phpstan.neon`

**Режим `create`** — записать через `Write`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
    - vendor/tomasvotruba/bladestan/config/extension.neon
    - vendor/shipmonk/dead-code-detector/rules.neon

parameters:
    level: 5 #Level лучше постепенно поднимать до 8–max.
    paths:
        - app/
        - routes/
```

(строку `vendor/nesbot/carbon/extension.neon` включать только если подтверждено на шаге 3.2).

**Режим `enhance`** (если пользователь подтвердил на шаге 2.2) — прочитать существующий `phpstan.neon` и через `Edit` точечно добавить недостающие строки в блок `includes`, не трогая пользовательские `level`, `paths` и прочие параметры.

### 3.4 Baseline

Только если на шаге 2.1 выбрано «существующий проект»:

```bash
vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G
```

После успешной генерации добавить `- phpstan-baseline.neon` последней строкой в `includes` файла `phpstan.neon`.

### 3.5 Поднятие лимита памяти по умолчанию

Прочитать `composer.json`.

- **Ключа `scripts.analyse` нет** → добавить (сохранив остальные существующие `scripts`, если есть):
  ```json
  "scripts": {
      "analyse": "phpstan analyse --memory-limit=1G"
  }
  ```
- **Ключ `scripts.analyse` уже есть и отличается** → `AskUserQuestion`: перезаписать текущим значением или оставить как есть.

---

## Шаг 4: Итог

Вывести на языке `language.ui` (если определён, иначе на языке пользователя):

1. **Установлено**: список реально добавленных пакетов (пустой список — «все три пакета уже были установлены»).
2. **Создано/изменено**: `phpstan.neon` (create/enhance — с чем именно), `phpstan-baseline.neon` (если сгенерирован), `composer.json` (`scripts.analyse`, если добавлен/изменён).
3. **Как запускать**: `composer analyse` (лимит памяти уже встроен — не нужно каждый раз добавлять `--memory-limit=1G`).
4. **Рекомендация**: постепенно поднимать `level` в `phpstan.neon` с 5 до 8/max по мере исправления ошибок.

---

## Правила

1. **Только реально отсутствующие пакеты** — не переустанавливать уже присутствующие в `composer.json`
2. **Не перезаписывать существующий `phpstan.neon` молча** — только по подтверждению, и только недостающие `includes`
3. **Пользовательские `level`/`paths` неприкосновенны** в Enhance-режиме
4. **Carbon — по факту наличия файла**, не по предположению версии
5. **Baseline — по прямому вопросу**, не по эвристике (число файлов, git log и т.п. ненадёжны)
6. **`scripts.analyse` не перезаписывается без подтверждения**, если уже задан и отличается
7. **Skill-context обязателен** — если `.kodla/skill-context/kodla-larastan/SKILL.md` существует, применять его правила
8. **Не устанавливать `phpstan/phpstan` явно** — приходит транзитивно через `larastan/larastan`
