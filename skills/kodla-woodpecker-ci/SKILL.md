---
name: kodla-woodpecker-ci
description: >-
  Генерирует конфигурации Woodpecker CI pipeline в .woodpecker/ для проекта.
  Анализирует tech-стек (PHP, Node.js, Go), предлагает docker/local/multi-backend
  варианты, применяет best practices из документации (LFS, labels, volumes).
  Используй когда нужно настроить CI пайплайн для проекта с Woodpecker CI,
  или когда пользователь говорит "создай pipeline", "настрой woodpecker", "добавь CI".
argument-hint: "[php|node|go|multi]"
allowed-tools: Read Edit Write Glob Grep Bash(git branch --show-current) Bash(git remote get-url origin) AskUserQuestion
disable-model-invocation: false
metadata:
  author: Kodla
  version: "1.0"
  category: ci-cd
---

# Woodpecker CI Pipeline Generator

Генерирует или улучшает конфигурации Woodpecker CI для проекта в `.woodpecker/`.
Применяет best practices и предотвращает типичные ошибки из документации.

**Два режима:**
- **Create** — файлы `.woodpecker/` отсутствуют → создать с нуля по шаблону
- **Enhance** — файлы уже есть → добавить pitfall-fixes и недостающие шаги

---

## Шаг 0: Загрузка контекста

Прочитай контекст проекта:

```
Read .kodla/config.yaml       (lang, framework, paths)
Read .kodla/DESCRIPTION.md    (если существует)
```

**Обязательно прочитай** `references/PITFALLS.md` из директории этого навыка —
держи в голове все 6 ловушек при генерации любого файла.

**Прочитай `.kodla/skill-context/kodla-woodpecker-ci/SKILL.md`** если файл существует.
Правила из skill-context имеют **приоритет** над общими инструкциями этого навыка.

---

## Шаг 1: Анализ проекта

### 1.1 Определение tech-стека

Порядок приоритетов:
1. `.kodla/config.yaml` → поле `lang` (php, node, go, python)
2. Файловые маркеры (Glob):
   - `composer.json` → **PHP**
   - `package.json` → **Node.js**
   - `go.mod` → **Go**
   - `pyproject.toml` / `requirements.txt` → **Python**
3. Если определить невозможно → спросить у пользователя

### 1.2 Определение режима

Glob: `.woodpecker/*.yml`, `.woodpecker/*.yaml`, `.woodpecker.yml`, `.woodpecker.yaml`

- **Если файлы найдены** → `MODE = enhance`
  - Прочитай существующие файлы
  - Найди отсутствующие pitfall-fixes (LFS, labels уровень, image для local)
  - Сообщи что найдено, уточни что улучшить

- **Если файлов нет** → `MODE = create`

---

## Шаг 2: Вопросы пользователю

### 2.1 Тип backend (AskUserQuestion)

```
Какой тип backend использует твой Woodpecker CI сервер?
```

Варианты:
- **docker** — все шаги выполняются в Docker-контейнерах (стандартный вариант)
- **local** — все шаги на хосте напрямую через bash/sh (WOODPECKER_BACKEND=local)
- **multi** — build в Docker + deploy на хосте (два агента, рекомендуется для деплоя)

### 2.2 Триггеры (AskUserQuestion, multiSelect)

```
На какие события запускать pipeline?
```

Варианты (можно несколько):
- push на master/main
- pull_request
- tag
- все события (wildcard)

Если выбран **push** → уточнить ветку (прочитать из `git branch --show-current`, предложить как default).

### 2.3 Только для multi backend

```
Путь к deploy-скрипту на сервере? (например: /var/www/myapp/deploy.sh)
```

---

## Шаг 3: Генерация файлов

### 3.1 Выбор шаблона

Прочитай подходящий шаблон из `templates/`:

| Tech-стек | Backend | Шаблон → Выходной файл |
|-----------|---------|------------------------|
| PHP | docker | `templates/php-docker.yml` → `.woodpecker/pipeline.yml` |
| PHP | multi | `templates/php-multi-build.yml` → `.woodpecker/build.yml` |
| PHP | multi | `templates/php-multi-deploy.yml` → `.woodpecker/deploy.yml` |
| Node.js | docker | `templates/node-docker.yml` → `.woodpecker/pipeline.yml` |
| Go | docker | `templates/go-docker.yml` → `.woodpecker/pipeline.yml` |
| Любой | local | `templates/generic-local.yml` → `.woodpecker/pipeline.yml` |

Если tech-стек не PHP/Node/Go и backend = docker → используй `templates/node-docker.yml`
как ближайший шаблон и адаптируй image.

### 3.2 Подстановки в шаблоне

Перед записью замени в тексте шаблона:
- `{{BRANCH}}` → ветка из git или выбор пользователя (master/main)
- `{{PROJECT_NAME}}` → имя из composer.json/package.json поле `name`, или basename директории
- `{{DEPLOY_SCRIPT}}` → путь к deploy-скрипту (только для multi)

### 3.3 Запись файлов

**Для single backend** (docker или local):
- Создай директорию `.woodpecker/` если не существует
- Запиши `.woodpecker/pipeline.yml`

**Для multi backend**:
- Запиши `.woodpecker/build.yml` (docker часть из шаблона)
- Запиши `.woodpecker/deploy.yml` (local часть из шаблона)

### 3.4 Обязательная проверка перед записью

Перед записью каждого файла убедись (сверь с PITFALLS.md):

- [ ] `clone.git.settings.lfs: false` присутствует
- [ ] `labels` находятся на уровне workflow (не внутри steps)
- [ ] Для local backend: `image: bash` (не docker image name)
- [ ] Если есть tar → команда пишет в `/tmp`, потом `mv`
- [ ] Если есть volumes → есть комментарий `# requires Trust + WOODPECKER_BACKEND_DOCKER_VOLUMES`

---

## Шаг 4: Показ результата

После записи файлов выведи:

1. **Что создано**: список файлов с путями
2. **Ключевые решения**: кратко почему выбран именно этот шаблон
3. **Следующие шаги** (если нужны действия в UI Woodpecker):
   - Если используются volumes: "Включи Trust в Settings репозитория"
   - Если multi backend: "Убедись что оба агента (docker + local) запущены"
4. Для enhance-режима: список что было исправлено/добавлено

---

## Правила

1. **Всегда читай PITFALLS.md** перед генерацией — не полагайся на память
2. **labels только на уровне workflow** — никогда внутри steps
3. **lfs: false всегда** — даже если пользователь не просил
4. **Для local backend** — image это имя shell-бинаря (bash, sh), не docker image
5. **Tar через /tmp** — создавать архив в /tmp, потом mv в workspace
6. **Не создавай** systemd unit файлы или конфиги сервера Woodpecker
7. **Сохраняй существующий контент** в enhance-режиме, только добавляй/исправляй

## КРИТИЧНО

- Никогда не пиши `labels` внутри `steps:` — они там игнорируются молча, это классическая ловушка
- Никогда не используй docker image (`alpine:3.20`, `composer:2`) как `image:` для local backend
- Всегда добавляй `depends_on: [build]` в deploy.yml при multi-backend паттерне
